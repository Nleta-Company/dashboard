<?php

/* =========================================================
   NLETA OWNER — VIEW SAF / PAT / SAT CHECKLIST
   ---------------------------------------------------------
   CHECKLIST:
       CHK-01 = PAT
       CHK-02 = SAT
       CHK-03 = SCI
       Revision: Rev.1

   UPDATED RULES:
       - Dynamic floor-wise landing-door checklist supported
       - Result codes: C / NC / NA / CA
       - NC / CA evidence: Photograph OR Video — at least ONE required
       - Photo and Video are both supported; either one is sufficient
       - NC requires Engineering Remark
       - CA requires Conditional Acceptance Remark / Condition
       - OWNER SEES NC AND CA CHECKPOINTS
       - CA is Conditional Acceptance; no Deferred/D result
       - ALL DISPLAYED TIMES ARE IST

   PRESERVED:
       - Owner authentication
       - Owner/job authorization
       - SAF information
       - Inspector information
       - Technician information (legacy, if present)
       - Checklist summary
       - Evidence links
       - Section remarks
       - Owner approval information
       - Report request workflow

   CA DECISION FIX:
       - Floor-wise SAT/PAT/SCI CA rows preserve owner_ca_decision
         while being flattened for the Owner CA table.
       - Fixed, SAT matrix and floor CA decisions are displayed after reload.
       - All CA Accept/Reject forms submit to this same main file.

   OWNER ACTIONS:
       - Service Complete closes the selected PAT/SAT service after final report delivery
       - Request for Certificate sends certificate_status = requested for the selected service
       - All actions are owner-authorized and CSRF protected
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';

require_role('owner');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function evidenceUrl(string $storedPath): string
{
    $storedPath = str_replace(
        '\\',
        '/',
        trim($storedPath)
    );

    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath)) {
        return $storedPath;
    }

    return '../' . ltrim($storedPath, '/');
}

/*
 * All application/database timestamps are explicitly displayed
 * using Asia/Kolkata. This avoids relying only on PHP's default
 * timezone interpretation.
 */
function formatIstDate(
    $value,
    string $format = 'd / m / Y h:i A'
): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $raw = trim((string)$value);

        /*
         * IMPORTANT:
         * This helper is ONLY for timestamps already stored as IST
         * or timestamps carrying their own timezone/offset.
         *
         * Examples in this page:
         *   - confirmed_at_ist
         *   - pat_completed_at_ist
         *   - sat_completed_at_ist
         *   - sci_completed_at_ist
         *
         * Plain MySQL DATETIME values from the database must NOT be
         * sent through this helper when the database stores UTC.
         * Use formatDbUtcToIst() for those values.
         */
        $hasTimezone = (bool)preg_match(
            '/(?:Z|[+-]\d{2}:?\d{2})$/i',
            $raw
        );

        if ($hasTimezone) {
            $dt = new DateTime($raw);
        } else {
            $dt = new DateTime(
                $raw,
                new DateTimeZone('Asia/Kolkata')
            );
        }

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return h($dt->format($format));

    } catch (Throwable $e) {
        return h($value);
    }
}

/*
 * MySQL timestamp -> IST
 * -----------------------
 * This NLETA installation is currently storing MySQL DATETIME
 * values such as maintenance_jobs.updated_at in UTC.
 *
 * MySQL DATETIME has no timezone information, so DateTime cannot
 * know that the stored value is UTC unless we explicitly tell it.
 *
 * Therefore:
 *     DB 2026-09-17 05:02:00 UTC
 * becomes
 *     IST 2026-09-17 10:32:00
 *
 * DO NOT use this helper for JSON fields whose key explicitly
 * says *_ist and which were created with PHP date() after the
 * Asia/Kolkata timezone was set.
 */
function formatDbUtcToIst(
    $value,
    string $format = 'd / m / Y h:i A'
): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $raw = trim((string)$value);

        $hasTimezone = (bool)preg_match(
            '/(?:Z|[+-]\d{2}:?\d{2})$/i',
            $raw
        );

        if ($hasTimezone) {
            $dt = new DateTime($raw);
        } else {
            $dt = new DateTime(
                $raw,
                new DateTimeZone('UTC')
            );
        }

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return h($dt->format($format));

    } catch (Throwable $e) {
        return h($value);
    }
}

function resultClass(string $result): string
{
    return match (strtoupper(trim($result))) {
        'C', 'OK' => 'result-c',
        'NC'      => 'result-nc',
        'NA'      => 'result-na',
        'CA'      => 'result-ca',
        default   => 'result-other',
    };
}


function resultLabel(string $result): string
{
    $result = strtoupper(trim($result));

    return match ($result) {
        'C'  => 'C',
        'OK' => 'C',
        'NC' => 'NC',
        'NA' => 'NA',
        'CA' => 'CA',
        default => $result !== '' ? $result : '—',
    };
}


/* =========================================================
   CHECKPOINT DISPLAY DATA — SAVED CHECKLIST IS SOURCE OF TRUTH
   ---------------------------------------------------------
   IMPORTANT:
   The Owner page does NOT maintain a separate PAT/SAT/SCI
   checkpoint master list.

   The Inspector checklist files save the checkpoint metadata
   into maintenance_jobs.checklist JSON. This page reads that
   submitted snapshot directly.

   Therefore Owner automatically sees the exact:
       - checkpoint ID
       - checkpoint name
       - priority
       - section
       - description
       - result
       - remarks
       - technical justification
       - evidence

   for BOTH Lift and Escalator inspections.

   No PAT/SAT/SCI names or priorities are hardcoded here.
========================================================= */

function firstNonEmpty(array $item, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($item[$key]) && is_scalar($item[$key])) {
            $value = trim((string)$item[$key]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

/*
 * Resolve display metadata ONLY from the saved Inspector row.
 *
 * The extra parameters are retained for compatibility with the
 * existing HTML calls in this file, but they are intentionally
 * ignored. This prevents Owner from falling back to a stale
 * hardcoded PAT/SAT/SCI master.
 */
function checkpointDisplayMeta(
    string $ref,
    array $item,
    array $meta = [],
    array $unusedFallback = []
): array {
    /*
     * SOURCE OF TRUTH = SAVED INSPECTOR SUBMISSION
     * ---------------------------------------------------------
     * Owner must display the exact checkpoint snapshot saved by
     * the Inspector: name + priority + section.
     * No separate PAT/SAT/SCI master is used here.
     */
    $name = firstNonEmpty(
        $item,
        [
            'checkpoint_name',
            'checkpoint',
            'name',
            'title',
            'label',
            'description',
            'engineering_checkpoint',
            'text'
        ]
    );

    $priority = firstNonEmpty(
        $item,
        [
            'priority',
            'Priority',
            'checkpoint_priority'
        ]
    );

    $section = firstNonEmpty(
        $item,
        [
            'section',
            'Section',
            'section_name',
            'group',
            'category'
        ]
    );

    /* Compatibility with saved nested metadata. */
    if (
        isset($item['checkpoint_metadata'])
        && is_array($item['checkpoint_metadata'])
    ) {
        if ($priority === '') {
            $priority = firstNonEmpty(
                $item['checkpoint_metadata'],
                ['priority', 'Priority', 'checkpoint_priority']
            );
        }

        if ($section === '') {
            $section = firstNonEmpty(
                $item['checkpoint_metadata'],
                ['section', 'Section', 'section_name', 'group', 'category']
            );
        }

        if ($name === '') {
            $name = firstNonEmpty(
                $item['checkpoint_metadata'],
                [
                    'checkpoint_name',
                    'checkpoint',
                    'name',
                    'title',
                    'label',
                    'description',
                    'engineering_checkpoint',
                    'text'
                ]
            );
        }
    }

    /*
     * Some Inspector save handlers keep a checkpoint_metadata map
     * at checklist level. Read it only when the row itself does not
     * contain the field. This is still submitted inspection data,
     * not a hardcoded checklist fallback.
     */
    if (
        isset($meta[$ref])
        && is_array($meta[$ref])
    ) {
        $savedMeta = $meta[$ref];

        if ($priority === '') {
            $priority = firstNonEmpty(
                $savedMeta,
                ['priority', 'Priority', 'checkpoint_priority']
            );
        }

        if ($section === '') {
            $section = firstNonEmpty(
                $savedMeta,
                ['section', 'Section', 'section_name', 'group', 'category']
            );
        }

        if ($name === '') {
            $name = firstNonEmpty(
                $savedMeta,
                [
                    'checkpoint_name',
                    'checkpoint',
                    'name',
                    'title',
                    'label',
                    'description',
                    'engineering_checkpoint',
                    'text'
                ]
            );
        }
    }

    return [
        'name' => $name !== '' ? $name : 'Checkpoint name not recorded',
        'priority' => $priority !== '' ? $priority : '—',
        'section' => $section !== '' ? $section : '—'
    ];
}

/*
 * No checklist master/fallback arrays are defined in this page.
 * PAT, SAT and SCI — Lift and Escalator — all use the exact
 * metadata saved by the Inspector submission.
 */
$checkpointMeta = [];
$patCheckpointFallbackNames = [];

/* =========================================================
   OWNER / JOB
========================================================= */

$ownerId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$jobId = (int)(
    $_GET['job_id']
    ?? 0
);

if ($ownerId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: approvals.php', true, 302);
    exit;
}


/* =========================================================
   LOAD JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.technician_id,

        mj.status AS job_status,
        /*
         * MySQL DATETIME values are stored by this installation in UTC.
         * They are converted to IST only when displayed.
         */
        mj.created_at,
        mj.updated_at,

        mj.checklist,

        mj.owner_remark,

        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.proposed_inspection_date,
        s.status AS saf_status,

        owner.name AS owner_name,
        tech.name AS technician_name,
        inspector.name AS inspector_name,

        a.asset_name,
        a.display_asset_id,
        a.location,
        a.asset_type,
        a.category

    FROM maintenance_jobs mj

    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id

    LEFT JOIN users owner
        ON owner.id = mj.owner_id

    LEFT JOIN users tech
        ON tech.id = mj.technician_id

    LEFT JOIN inspector_assignments ia
        ON ia.job_id = mj.id

    LEFT JOIN users inspector
        ON inspector.id = ia.inspector_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        mj.id = ?
        AND mj.owner_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
");

if (!$stmt) {
    die(
        'Unable to prepare checklist query: '
        . h($conn->error)
    );
}

$stmt->bind_param(
    'ii',
    $jobId,
    $ownerId
);

if (!$stmt->execute()) {
    die(
        'Unable to load checklist: '
        . h($stmt->error)
    );
}

$job = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$job) {
    die(
        'Checklist not found or it does not belong to your account.'
    );
}


/* =========================================================
   DECODE CHECKLIST
========================================================= */

$checklist = [];

if (!empty($job['checklist'])) {

    $decoded = json_decode(
        $job['checklist'],
        true
    );

    if (is_array($decoded)) {
        $checklist = $decoded;
    }
}

$results = (
    isset($checklist['results'])
    &&
    is_array($checklist['results'])
)
    ? $checklist['results']
    : [];

/*
 * =========================================================
 * SAT MATRIX RESULTS — SOURCE OF TRUTH
 * ---------------------------------------------------------
 * SAT stores load/readings separately from fixed checkpoint
 * results under checklist['matrices'].
 *
 * Each matrix row contains:
 *   - ref (e.g. A|up_current|50%)
 *   - checkpoint_name
 *   - priority
 *   - section
 *   - matrix_key
 *   - load_condition
 *   - actual_reading
 *   - result (C/NC/NA/CA)
 *   - remark
 *   - technical_justification
 *   - evidence
 *
 * Owner must display SAT matrix NC/CA rows exactly like the
 * fixed SAT checkpoints, including the actual reading.
 * =========================================================
 */
$matrices = (
    isset($checklist['matrices'])
    &&
    is_array($checklist['matrices'])
)
    ? $checklist['matrices']
    : [];

$sectionRemarks = (
    isset($checklist['section_remarks'])
    &&
    is_array($checklist['section_remarks'])
)
    ? $checklist['section_remarks']
    : [];

$ownerApproval = (
    isset($checklist['owner_approval'])
    &&
    is_array($checklist['owner_approval'])
)
    ? $checklist['owner_approval']
    : [];


/* =========================================================
   CHECKLIST META
========================================================= */

$checklistCode = (string)(
    $checklist['checklist_code']
    ?? ''
);

$checklistRevision = (string)(
    $checklist['checklist_revision']
    ?? 'Rev.1'
);

$module = (string)(
    $checklist['module']
    ?? ''
);

$finalStatus = (string)(
    $checklist['final_status']
    ?? '—'
);

/* =========================================================
   SERVICE / MODULE MODE
   ---------------------------------------------------------
   CHK-01 = PAT
   CHK-02 = SAT
   The same Owner page can therefore display PAT, SAT or SCI.
========================================================= */

$serviceCode = strtoupper(trim((string)(
    $job['service_code']
    ?? $checklist['service_code']
    ?? ''
)));

$isSci = ($serviceCode === 'SCI' || strtoupper($module) === 'SCI');
$isSat = (!$isSci && ($serviceCode === 'SAT' || strtoupper($module) === 'SAT'));
$isPat = (!$isSci && !$isSat);

$defaultChecklistCode = $isSci
    ? 'CHK-03'
    : ($isSat ? 'CHK-02' : 'CHK-01');

$defaultModule = $isSci
    ? 'SCI'
    : ($isSat ? 'SAT' : 'PAT');

if (trim($checklistCode) === '') {
    $checklistCode = $defaultChecklistCode;
}

if (trim($module) === '') {
    $module = $defaultModule;
}

$displayChecklistTitle = $isSci
    ? 'SCI Checklist — CHK-03 Rev.1'
    : ($isSat
        ? 'SAT Checklist — CHK-02 Rev.1'
        : 'PAT Checklist — CHK-01 Rev.1');

$displayServiceName = $isSci
    ? ($job['service_name'] ?: 'Safety Compliance Inspection')
    : ($isSat
        ? ($job['service_name'] ?: 'Satisfactory Acceptance Test')
        : ($job['service_name'] ?: 'Partial Acceptance Test'));



/* =========================================================
   CHECKLIST ORDER — DIRECTLY FROM SAVED INSPECTOR SUBMISSION
   ---------------------------------------------------------
   Do NOT create PAT/SAT/SCI master arrays here.

   The Inspector save files write the submitted checkpoints
   into checklist.results in their actual checklist order.
   We preserve that order exactly.

   This works for:
       - Lift PAT / SAT / SCI
       - Escalator PAT / SAT / SCI
       - Dynamic floor checkpoints
       - Future checklist additions

   Legacy/additional saved rows are also preserved because the
   entire saved results array is displayed.
========================================================= */

$orderedResults = [];

foreach ($results as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    /*
     * Keep the exact insertion/order from the Inspector's
     * submitted checklist JSON.
     */
    $orderedResults[(string)$ref] = $item;
}

/*
 * SAT matrix rows are intentionally kept separate from fixed
 * checkpoint rows because their saved structure is different.
 * They are displayed in the same Owner NC/CA views below.
 */
$orderedMatrices = [];

foreach ($matrices as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $matrixRef = trim((string)(
        $item['ref']
        ?? $ref
        ?? ''
    ));

    if ($matrixRef === '') {
        continue;
    }

    $orderedMatrices[$matrixRef] = $item;
}

/*
 * Number of actual checkpoint rows submitted in this inspection.
 * There is intentionally no hardcoded "master count".
 */
$totalCount = count($orderedResults);
$masterCount = $totalCount;
$missingCount = 0;

/* =========================================================
   DATABASE EVIDENCE FALLBACK
   ---------------------------------------------------------
   Inspector evidence is stored in saf_checklist_evidence.
   Do NOT change the checklist result here. This block only
   merges photo/video/document paths into the corresponding
   checklist item so the Owner can see the uploaded evidence.
========================================================= */

$databaseEvidence = [];

$evidenceTableCheck = $conn->query("
    SHOW TABLES LIKE 'saf_checklist_evidence'
");

if (
    $evidenceTableCheck
    &&
    $evidenceTableCheck->num_rows > 0
) {

    $evidenceStmt = $conn->prepare("
        SELECT
            ref_code,
            photo_path,
            video_path,
            document_path
        FROM saf_checklist_evidence
        WHERE job_id = ?
        ORDER BY id ASC
    ");

    if ($evidenceStmt) {

        $evidenceStmt->bind_param(
            'i',
            $jobId
        );

        if ($evidenceStmt->execute()) {

            $evidenceResult =
                $evidenceStmt->get_result();

            while (
                $evidenceRow =
                $evidenceResult->fetch_assoc()
            ) {

                $ref = trim(
                    (string)(
                        $evidenceRow['ref_code']
                        ?? ''
                    )
                );

                if ($ref === '') {
                    continue;
                }

                /*
                 * Keep the latest non-empty evidence value
                 * for each reference.
                 */
                if (!isset($databaseEvidence[$ref])) {
                    $databaseEvidence[$ref] = [
                        'photo' => '',
                        'video' => '',
                        'document' => ''
                    ];
                }

                $photoPath = trim(
                    (string)(
                        $evidenceRow['photo_path']
                        ?? ''
                    )
                );

                $videoPath = trim(
                    (string)(
                        $evidenceRow['video_path']
                        ?? ''
                    )
                );

                $documentPath = trim(
                    (string)(
                        $evidenceRow['document_path']
                        ?? ''
                    )
                );

                if (
                    $photoPath !== ''
                ) {
                    $databaseEvidence[$ref]['photo'] =
                        $photoPath;
                }

                if (
                    $videoPath !== ''
                ) {
                    $databaseEvidence[$ref]['video'] =
                        $videoPath;
                }

                if (
                    $documentPath !== ''
                ) {
                    $databaseEvidence[$ref]['document'] =
                        $documentPath;
                }
            }
        }

        $evidenceStmt->close();
    }
}

/*
 * Merge database evidence into fixed PAT results.
 * The result/remark already saved in checklist JSON is
 * intentionally left untouched.
 */
foreach (
    $databaseEvidence as $ref => $evidence
) {

    if (!isset($orderedResults[$ref])) {
        continue;
    }

    if (!is_array($orderedResults[$ref])) {
        $orderedResults[$ref] = [];
    }

    if (
        empty($orderedResults[$ref]['photo'])
        &&
        !empty($evidence['photo'])
    ) {
        $orderedResults[$ref]['photo'] =
            $evidence['photo'];
    }

    if (
        empty($orderedResults[$ref]['video'])
        &&
        !empty($evidence['video'])
    ) {
        $orderedResults[$ref]['video'] =
            $evidence['video'];
    }

    if (
        empty($orderedResults[$ref]['document'])
        &&
        !empty($evidence['document'])
    ) {
        $orderedResults[$ref]['document'] =
            $evidence['document'];
    }
}

/*
 * Merge database evidence into SAT matrix rows too.
 * Matrix evidence uses the exact matrix ref, for example:
 *     A|up_current|50%
 */
foreach ($orderedMatrices as $ref => &$matrixItem) {
    if (!is_array($matrixItem)) {
        $matrixItem = [];
    }

    if (
        isset($databaseEvidence[$ref])
        &&
        is_array($databaseEvidence[$ref])
    ) {
        $ev = $databaseEvidence[$ref];

        if (
            empty($matrixItem['photo'])
            && !empty($ev['photo'])
        ) {
            $matrixItem['photo'] = $ev['photo'];
        }

        if (
            empty($matrixItem['video'])
            && !empty($ev['video'])
        ) {
            $matrixItem['video'] = $ev['video'];
        }

        if (
            empty($matrixItem['document'])
            && !empty($ev['document'])
        ) {
            $matrixItem['document'] = $ev['document'];
        }
    }

    if (
        !isset($matrixItem['evidence'])
        || !is_array($matrixItem['evidence'])
    ) {
        $matrixItem['evidence'] = [];
    }

    if (
        empty($matrixItem['evidence']['photo'])
        && !empty($matrixItem['photo'])
    ) {
        $matrixItem['evidence']['photo'] = $matrixItem['photo'];
    }

    if (
        empty($matrixItem['evidence']['video'])
        && !empty($matrixItem['video'])
    ) {
        $matrixItem['evidence']['video'] = $matrixItem['video'];
    }

    if (
        empty($matrixItem['evidence']['document'])
        && !empty($matrixItem['document'])
    ) {
        $matrixItem['evidence']['document'] = $matrixItem['document'];
    }
}
unset($matrixItem);


/* =========================================================
   SUMMARY COUNTS
========================================================= */

$totalCount = 0;
$countC = 0;
$countNC = 0;
$countNA = 0;
$countCA = 0;

foreach ($orderedResults as $item) {

    if (!is_array($item)) {
        continue;
    }

    $totalCount++;

    $r = strtoupper(
        trim(
            (string)(
                $item['result']
                ?? ''
            )
        )
    );

    if (
        $r === 'OK'
        ||
        $r === 'C'
    ) {

        $countC++;

    } elseif (
        $r === 'NC'
    ) {

        $countNC++;

    } elseif (
        $r === 'NA'
    ) {

        $countNA++;

    } elseif (
        $r === 'CA'
    ) {

        $countCA++;
    }
}

/*
 * SAT matrix rows are execution checkpoints too. Include them in
 * Owner summary counts so SAT NC/CA totals match the Inspector.
 */
foreach ($orderedMatrices as $item) {

    if (!is_array($item)) {
        continue;
    }

    $totalCount++;

    $r = strtoupper(
        trim(
            (string)(
                $item['result']
                ?? ''
            )
        )
    );

    if ($r === 'OK' || $r === 'C') {
        $countC++;
    } elseif ($r === 'NC') {
        $countNC++;
    } elseif ($r === 'NA') {
        $countNA++;
    } elseif ($r === 'CA') {
        $countCA++;
    }
}

$masterCount = $totalCount;
$missingCount = 0;


/* =========================================================
   EVIDENCE COUNTS
   ---------------------------------------------------------
   Count evidence from the merged fixed PAT results and the
   dynamic floor checklist. Database evidence has already
   been merged into fixed PAT results above.
========================================================= */

$photoCount = 0;
$videoCount = 0;
$documentCount = 0;

foreach ($orderedResults as $item) {

    if (!is_array($item)) {
        continue;
    }

    if (
        !empty($item['photo'])
    ) {
        $photoCount++;
    }

    if (
        !empty($item['video'])
    ) {
        $videoCount++;
    }

    if (
        !empty($item['document'])
        ||
        !empty($item['document_path'])
    ) {
        $documentCount++;
    }
}

/* SAT matrix evidence counts */
foreach ($orderedMatrices as $item) {

    if (!is_array($item)) {
        continue;
    }

    if (!empty($item['photo'])) {
        $photoCount++;
    }

    if (!empty($item['video'])) {
        $videoCount++;
    }

    if (
        !empty($item['document'])
        ||
        !empty($item['document_path'])
    ) {
        $documentCount++;
    }
}


/* =========================================================
   OWNER APPROVAL
========================================================= */

$approvalStatus = strtolower(
    trim(
        (string)(
            $ownerApproval['status']
            ?? ''
        )
    )
);

$ownerReason = (string)(
    $ownerApproval['reason']
    ?? ''
);


/* =========================================================
   FLOOR-WISE RESULTS
   ---------------------------------------------------------
   D2 contains seven landing-door checks per floor.
   The Owner view shows only NC items, including floor-wise NCs.
   SAT matrix NC/CA rows are handled separately because they are
   stored under checklist['matrices'].
   Supports common storage formats:
       floor_checklist[floor][checkpoint] = item
       floor_results[floor][checkpoint]  = item
========================================================= */

$floorChecklist = [];

foreach (['floor_checklist', 'floor_results'] as $floorKey) {
    if (
        empty($checklist[$floorKey])
        ||
        !is_array($checklist[$floorKey])
    ) {
        continue;
    }

    foreach ($checklist[$floorKey] as $floor => $floorItems) {
        if (!is_array($floorItems)) {
            continue;
        }

        foreach ($floorItems as $checkpoint => $item) {
            if (!is_array($item)) {
                continue;
            }

            $floorChecklist[(string)$floor][(string)$checkpoint] = $item;
        }
    }
}

/*
 * Merge database evidence into dynamic floor checklist items.
 *
 * Floor evidence reference format used by the Inspector save
 * handler:
 *     FLOOR-{floor}-{checkpoint_key}
 *
 * Example:
 *     FLOOR-G-landing_door_alignment
 */
foreach (
    $floorChecklist as $floor => $floorItems
) {

    foreach (
        $floorItems as $checkpoint => $item
    ) {

        if (!is_array($item)) {
            continue;
        }

        $refCandidates = [
            'FLOOR-' . $floor . '-' . $checkpoint
        ];

        $matchedEvidence = null;

        foreach ($refCandidates as $candidate) {
            if (isset($databaseEvidence[$candidate])) {
                $matchedEvidence =
                    $databaseEvidence[$candidate];
                break;
            }
        }

        if ($matchedEvidence === null) {
            continue;
        }

        if (
            empty($floorChecklist[$floor][$checkpoint]['photo'])
            &&
            !empty($matchedEvidence['photo'])
        ) {
            $floorChecklist[$floor][$checkpoint]['photo'] =
                $matchedEvidence['photo'];
        }

        if (
            empty($floorChecklist[$floor][$checkpoint]['video'])
            &&
            !empty($matchedEvidence['video'])
        ) {
            $floorChecklist[$floor][$checkpoint]['video'] =
                $matchedEvidence['video'];
        }

        if (
            empty($floorChecklist[$floor][$checkpoint]['document'])
            &&
            !empty($matchedEvidence['document'])
        ) {
            $floorChecklist[$floor][$checkpoint]['document'] =
                $matchedEvidence['document'];
        }

        if (
            !isset(
                $floorChecklist[$floor][$checkpoint]['evidence']
            )
            ||
            !is_array(
                $floorChecklist[$floor][$checkpoint]['evidence']
            )
        ) {
            $floorChecklist[$floor][$checkpoint]['evidence'] = [];
        }

        if (
            empty(
                $floorChecklist[$floor][$checkpoint]['evidence']['photo']
            )
            &&
            !empty($matchedEvidence['photo'])
        ) {
            $floorChecklist[$floor][$checkpoint]['evidence']['photo'] =
                $matchedEvidence['photo'];
        }

        if (
            empty(
                $floorChecklist[$floor][$checkpoint]['evidence']['video']
            )
            &&
            !empty($matchedEvidence['video'])
        ) {
            $floorChecklist[$floor][$checkpoint]['evidence']['video'] =
                $matchedEvidence['video'];
        }
    }
}

/*
 * Count dynamic floor evidence after the database fallback.
 */
foreach ($floorChecklist as $floorItems) {

    foreach ($floorItems as $item) {

        if (!is_array($item)) {
            continue;
        }

        if (!empty($item['photo'])) {
            $photoCount++;
        }

        if (!empty($item['video'])) {
            $videoCount++;
        }

        if (
            !empty($item['document'])
            ||
            !empty($item['document_path'])
        ) {
            $documentCount++;
        }
    }
}


/*
 * Build display metadata directly from each saved Inspector row.
 * There is no master/fallback lookup.
 */
$checkpointMeta = [];
$displayMeta = [];

foreach ($orderedResults as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $displayMeta[(string)$ref] =
        checkpointDisplayMeta(
            (string)$ref,
            $item
        );
}

foreach ($floorChecklist as $floor => $floorItems) {
    foreach ($floorItems as $checkpoint => $item) {
        if (!is_array($item)) {
            continue;
        }

        $displayMeta[
            'FLOOR|' . $floor . '|' . $checkpoint
        ] =
            checkpointDisplayMeta(
                (string)$checkpoint,
                $item
            );
    }
}

$floorNcItems = [];

foreach ($floorChecklist as $floor => $floorItems) {
    foreach ($floorItems as $checkpoint => $item) {

        $r = strtoupper(
            trim(
                (string)(
                    $item['result'] ?? ''
                )
            )
        );

        if ($r !== 'NC') {
            continue;
        }

        $floorNcItems[] = [
            'floor' => $floor,
            'checkpoint' => $checkpoint,
            'result' => 'NC',
            'remark' => (string)($item['remark'] ?? ''),
            'technical_justification' => (string)(
                $item['ca_remark']
                ?? $item['technical_justification']
                ?? ''
            ),
            'photo' => (string)($item['photo'] ?? ''),
            'video' => (string)($item['video'] ?? ''),
            'evidence' => is_array($item['evidence'] ?? null)
                ? $item['evidence']
                : []
        ];
    }
}

$ownerNcItems = [];

foreach ($orderedResults as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $savedResult = strtoupper(
        trim((string)($item['result'] ?? ''))
    );

    if ($savedResult === 'NC') {
        $ownerNcItems[$ref] = $item;
    }
}

/*
 * SAT MATRIX NC ITEMS
 * ---------------------------------------------------------
 * Matrix rows are saved under checklist['matrices'], not
 * checklist['results']. Add every matrix row marked NC so
 * Owner sees all SAT non-compliance points, including the
 * actual reading and engineering remark.
 */
$ownerNcMatrixItems = [];

foreach ($orderedMatrices as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $savedResult = strtoupper(
        trim((string)($item['result'] ?? ''))
    );

    if ($savedResult === 'NC') {
        $ownerNcMatrixItems[$ref] = $item;
    }
}

/*
 * ROPE SUPPLEMENTARY ASSESSMENT
 * ---------------------------------------------------------
 * Rope is supplementary and does NOT increase the fixed PAT
 * checkpoint count. When it evaluates to NC or CA, however,
 * it must be visible to the Owner/Client with its evidence.
 */
$ropeSupplementary = (
    isset($checklist['rope_supplementary'])
    && is_array($checklist['rope_supplementary'])
)
    ? $checklist['rope_supplementary']
    : [];

$ropeResult = strtoupper(
    trim((string)($ropeSupplementary['result'] ?? ''))
);

$ropeOwnerItem = [];

if (in_array($ropeResult, ['NC', 'CA'], true)) {

    $ropeEvidence = is_array(
        $ropeSupplementary['evidence'] ?? null
    )
        ? $ropeSupplementary['evidence']
        : [];

    $ropeOwnerItem = [
        'checkpoint_id' => 'ROPE-SUP-01',
        'checkpoint_name' => (string)(
            $ropeSupplementary['checkpoint_name']
            ?? 'Rope physical condition / replacement age assessment'
        ),
        'priority' => 'P1',
        'section' => 'L',
        'result' => $ropeResult,
        'remark' => '',
        'technical_justification' => (string)(
            $ropeSupplementary['technical_justification']
            ?? ''
        ),
        'physical_condition' => (string)(
            $ropeSupplementary['physical_condition']
            ?? ''
        ),
        'replacement_date' => (string)(
            $ropeSupplementary['replacement_date']
            ?? ''
        ),
        'age_years' => $ropeSupplementary['age_years'] ?? null,
        'photo' => (string)($ropeEvidence['photo'] ?? ''),
        'video' => (string)($ropeEvidence['video'] ?? ''),
        'document' => '',
        'evidence' => $ropeEvidence,
        'owner_client_visible' => true
    ];

    if (isset($databaseEvidence['ROPE-SUP-01'])) {
        if (
            $ropeOwnerItem['photo'] === ''
            && !empty($databaseEvidence['ROPE-SUP-01']['photo'])
        ) {
            $ropeOwnerItem['photo'] =
                (string)$databaseEvidence['ROPE-SUP-01']['photo'];
        }

        if (
            $ropeOwnerItem['video'] === ''
            && !empty($databaseEvidence['ROPE-SUP-01']['video'])
        ) {
            $ropeOwnerItem['video'] =
                (string)$databaseEvidence['ROPE-SUP-01']['video'];
        }
    }

    $displayMeta['ROPE-SUP-01'] = [
        'priority' => 'P1',
        'section' => 'L',
        'name' => $ropeOwnerItem['checkpoint_name']
    ];
}

if ($ropeResult === 'NC') {
    $ownerNcItems['ROPE-SUP-01'] = $ropeOwnerItem;
}

$ownerNcTotal = count($ownerNcItems) + count($ownerNcMatrixItems) + count($floorNcItems);

/* =========================================================
   OWNER CONDITIONAL-ACCEPTANCE ITEMS
   ---------------------------------------------------------
   SCI/SAT/PAT CA items are shown separately so the Owner can see the
   condition/technical justification. PAT behavior remains
   unchanged because PAT CA is also supported by the common
   result model.
========================================================= */

$ownerCaItems = [];

foreach ($orderedResults as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $savedResult = strtoupper(trim((string)($item['result'] ?? '')));

    if ($savedResult === 'CA') {
        $ownerCaItems[$ref] = $item;
    }
}

/*
 * SAT MATRIX CA ITEMS
 * ---------------------------------------------------------
 * Keep matrix Conditional Acceptance rows separate so the
 * Owner sees the load condition, actual reading, condition/
 * technical justification, evidence and Owner decision.
 */
$ownerCaMatrixItems = [];

foreach ($orderedMatrices as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $savedResult = strtoupper(
        trim((string)($item['result'] ?? ''))
    );

    if ($savedResult === 'CA') {
        $ownerCaMatrixItems[$ref] = $item;
    }
}

$floorCaItems = [];

foreach ($floorChecklist as $floor => $floorItems) {
    foreach ($floorItems as $checkpoint => $item) {
        if (!is_array($item)) {
            continue;
        }

        $r = strtoupper(trim((string)($item['result'] ?? '')));

        if ($r !== 'CA') {
            continue;
        }

        $floorCaItems[] = [
            'floor' => $floor,
            'checkpoint' => $checkpoint,
            'result' => 'CA',
            'remark' => (string)($item['remark'] ?? ''),
            'technical_justification' => (string)(
                $item['ca_remark']
                ?? $item['technical_justification']
                ?? ''
            ),
            'photo' => (string)($item['photo'] ?? ''),
            'video' => (string)($item['video'] ?? ''),
            'document' => (string)($item['document'] ?? ''),
            'evidence' => is_array($item['evidence'] ?? null)
                ? $item['evidence']
                : [],
            /* Preserve the saved Owner CA decision when the floor row
             * is flattened for display. Without this field the decision
             * exists in maintenance_jobs.checklist but the Owner page
             * loses it while building $floorCaItems. */
            'owner_ca_decision' => (
                isset($item['owner_ca_decision'])
                && is_array($item['owner_ca_decision'])
            )
                ? $item['owner_ca_decision']
                : []
        ];
    }
}

if ($ropeResult === 'CA') {
    $ownerCaItems['ROPE-SUP-01'] = $ropeOwnerItem;
}

$ownerCaTotal = count($ownerCaItems) + count($ownerCaMatrixItems) + count($floorCaItems);


$ownerResponsibilityConfirmation = (
    isset($checklist['owner_responsibility_confirmation'])
    && is_array($checklist['owner_responsibility_confirmation'])
)
    ? $checklist['owner_responsibility_confirmation']
    : [];

$ownerResponsibilityConfirmed =
    (($ownerResponsibilityConfirmation['status'] ?? '') === 'confirmed')
    || (($ownerResponsibilityConfirmation['owner_responsibility_confirmed'] ?? false) === true);

$ownerResponsibilityDocument =
    trim((string)($ownerResponsibilityConfirmation['document'] ?? ''));

$ownerResponsibilityDocumentName =
    trim((string)($ownerResponsibilityConfirmation['document_name'] ?? ''));

$ownerResponsibilityConfirmedAt =
    trim((string)($ownerResponsibilityConfirmation['confirmed_at_ist'] ?? ''));



/* =========================================================
   OWNER RESPONSIBILITY STATUS
========================================================= */

function ownerResponsibilityData(array $item): array
{
    $data = (
        isset($item['owner_responsibility'])
        && is_array($item['owner_responsibility'])
    )
        ? $item['owner_responsibility']
        : [];

    return [
        'confirmed' =>
            (($data['owner_responsibility_confirmed'] ?? false) === true)
            || (($data['status'] ?? '') === 'confirmed'),
        'document' =>
            trim((string)($data['document'] ?? '')),
        'document_name' =>
            trim((string)($data['document_name'] ?? '')),
        'confirmed_at' =>
            trim((string)($data['confirmed_at_ist'] ?? '')),
    ];
}


/* =========================================================
   DISPLAY VALUES
========================================================= */

$displayAsset =
    $job['display_asset_id']
    ?:
    $job['asset_name']
    ?:
    '—';

$inspectionDate = '—';

if (
    !empty(
        $job['proposed_inspection_date']
    )
) {

    $inspectionDate =
        formatIstDate(
            $job['proposed_inspection_date'],
            'd / m / Y'
        );
}


/* =========================================================
   JOB STATUS
========================================================= */

$statusText = 'Pending Approval';
$statusClass = 'pending';

if (
    $job['job_status']
    ===
    'approved'
) {

    $statusText = 'Approved';
    $statusClass = 'approved';

} elseif (
    $approvalStatus
    ===
    'rejected'
) {

    $statusText =
        'Rejected / Returned';

    $statusClass =
        'rejected';

} elseif (
    $job['job_status']
    ===
    'pending_owner'
) {

    $statusText =
        'Pending Owner Approval';

    $statusClass =
        'pending';

} elseif (
    $job['job_status']
    ===
    'in_progress'
) {

    $statusText =
        'In Progress';

    $statusClass =
        'scheduled';
}


/* =========================================================
   FINAL PAYMENT GATE
   ---------------------------------------------------------
   Report Request remains LOCKED until the FINAL payment for
   this FSO/PSGA is verified/paid by Admin.

   Final payment stage:
       Payment_Stage = 'Final'

   Unlock condition:
       Payment_Stage = Final
       AND Admin verification is recorded in the saved payment
       records (transaction, schedule, or overall payment status).

   This is READ-ONLY here. Admin payment verification remains
   the source of truth.
========================================================= */

function isFinalPaymentPaid(
    mysqli $conn,
    string $psgaNo,
    int $jobId = 0
): bool
{
    /*
     * =========================================================
     * FINAL PAYMENT VERIFICATION GATE — OWNER REPORT
     * =========================================================
     *
     * The Inspection Report is unlocked as soon as the FINAL payment
     * is shown as Paid in the Owner Payment Schedule, which means
     * the Admin-verified payment record is present.
     *
     * IMPORTANT:
     * Admin payment verification updates BOTH:
     *
     *   payment_transactions.Status   = Verified
     *   payment_schedule.Status       = Verified
     *
     * The earlier Owner gate depended too heavily on one exact
     * PSGA -> Offer -> Schedule path. This version checks the
     * saved job/SAF PSGA first and then verifies the FINAL stage
     * through all saved payment records belonging to that Offer.
     *
     * Accepted verification sources:
     *
     *   1. Final payment transaction = Verified (shown as Paid)
     *   2. Final payment schedule   = Paid / Verified
     *   3. Overall payment record   = Paid / Completed / Verified
     *
     * The final payment stage itself is ALWAYS required.
     * No Owner Approval / checklist approval flag is required.
     *
     * This function is READ-ONLY.
     * =========================================================
     */

    $psgaNo = trim($psgaNo);
    $jobId  = (int)$jobId;

    /*
     * ---------------------------------------------------------
     * A. Direct PSGA path
     * ---------------------------------------------------------
     */
    if ($psgaNo !== '') {

        $stmt = $conn->prepare("
            SELECT 1
            FROM payment_schedule ps

            LEFT JOIN payment_transactions pt
                ON pt.Schedule_ID = ps.Schedule_ID
                AND pt.Transaction_ID = (
                    SELECT MAX(pt2.Transaction_ID)
                    FROM payment_transactions pt2
                    WHERE pt2.Schedule_ID = ps.Schedule_ID
                )

            LEFT JOIN payments p
                ON p.Payment_ID = ps.Payment_ID

            INNER JOIN nleta_offer_master o
                ON o.id = COALESCE(
                    NULLIF(ps.Offer_ID, 0),
                    NULLIF(p.Offer_ID, 0)
                )

            WHERE
                TRIM(COALESCE(o.psga_no, '')) = ?

                AND REPLACE(
                        REPLACE(
                            REPLACE(
                                LOWER(
                                    TRIM(
                                        COALESCE(
                                            ps.Payment_Stage,
                                            ''
                                        )
                                    )
                                ),
                                ' ',
                                ''
                            ),
                            '_',
                            ''
                        ),
                        '-',
                        ''
                    ) IN (
                        'final',
                        'finalpayment'
                    )

                AND (
                    LOWER(TRIM(COALESCE(ps.Status, ''))) IN (
                        'verified',
                        'paid'
                    )

                    OR

                    LOWER(TRIM(COALESCE(pt.Status, ''))) = 'verified'

                    OR

                    LOWER(TRIM(COALESCE(p.Payment_Status, ''))) IN (
                        'paid',
                        'completed',
                        'verified'
                    )
                )

            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param(
                's',
                $psgaNo
            );

            if ($stmt->execute()) {

                $found =
                    $stmt
                        ->get_result()
                        ->fetch_row();

                if (!empty($found)) {
                    $stmt->close();
                    return true;
                }

            } else {

                error_log(
                    'NLETA Final Payment Gate direct query error: '
                    . $stmt->error
                );
            }

            $stmt->close();
        } else {

            error_log(
                'NLETA Final Payment Gate direct prepare error: '
                . $conn->error
            );
        }
    }


    /*
     * ---------------------------------------------------------
     * B. Exact JOB -> SAF -> PSGA fallback
     * ---------------------------------------------------------
     *
     * This protects against a stale/missing $job['psga_no'] value.
     *
     * job_id -> maintenance_jobs.saf_id
     *        -> saf_contracts.psga_no
     *        -> nleta_offer_master.psga_no
     *        -> payment_schedule
     *        -> payment_transactions
     * ---------------------------------------------------------
     */
    if ($jobId > 0) {

        $stmt = $conn->prepare("
            SELECT 1
            FROM maintenance_jobs mj

            INNER JOIN saf_contracts s
                ON s.id = mj.saf_id

            INNER JOIN nleta_offer_master o
                ON TRIM(COALESCE(o.psga_no, '')) =
                   TRIM(COALESCE(s.psga_no, ''))

            LEFT JOIN payments p
                ON p.Offer_ID = o.id

            INNER JOIN payment_schedule ps
                ON ps.Payment_ID = p.Payment_ID
                OR ps.Offer_ID = o.id

            LEFT JOIN payment_transactions pt
                ON pt.Schedule_ID = ps.Schedule_ID
                AND pt.Transaction_ID = (
                    SELECT MAX(pt2.Transaction_ID)
                    FROM payment_transactions pt2
                    WHERE pt2.Schedule_ID = ps.Schedule_ID
                )

            WHERE
                mj.id = ?

                AND REPLACE(
                        REPLACE(
                            REPLACE(
                                LOWER(
                                    TRIM(
                                        COALESCE(
                                            ps.Payment_Stage,
                                            ''
                                        )
                                    )
                                ),
                                ' ',
                                ''
                            ),
                            '_',
                            ''
                        ),
                        '-',
                        ''
                    ) IN (
                        'final',
                        'finalpayment'
                    )

                AND (
                    LOWER(TRIM(COALESCE(ps.Status, ''))) IN (
                        'verified',
                        'paid'
                    )

                    OR

                    LOWER(TRIM(COALESCE(pt.Status, ''))) = 'verified'

                    OR

                    LOWER(TRIM(COALESCE(p.Payment_Status, ''))) IN (
                        'paid',
                        'completed',
                        'verified'
                    )
                )

            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $jobId
            );

            if ($stmt->execute()) {

                $found =
                    $stmt
                        ->get_result()
                        ->fetch_row();

                if (!empty($found)) {
                    $stmt->close();
                    return true;
                }

            } else {

                error_log(
                    'NLETA Final Payment Gate job fallback query error: '
                    . $stmt->error
                );
            }

            $stmt->close();
        } else {

            error_log(
                'NLETA Final Payment Gate job fallback prepare error: '
                . $conn->error
            );
        }
    }


    return false;
}
$finalPaymentPaid = isFinalPaymentPaid(
    $conn,
    (string)($job['psga_no'] ?? ''),
    (int)($job['job_id'] ?? $jobId ?? 0)
);

/* =========================================================
   REPORT REQUEST UNLOCK CONDITION
   ---------------------------------------------------------
   IMPORTANT:
   Owner Approval Information is a SEPARATE workflow section.
   It must NOT control the Inspection Report request button.

   Inspection Report unlock condition:
       ONLY Final payment verified by Admin.

   Final payment verification is handled above and is based on:
       Payment_Stage = 'Final'
       AND (
           payment_schedule.Status = 'paid'/'verified'
           OR latest linked payment_transactions.Status = 'verified'
       )
========================================================= */

$reportRequestUnlocked = $finalPaymentPaid;

/* =========================================================
   REPORT REQUEST
========================================================= */

$reportRequest = null;
$reportTableExists = false;

$tableResult = $conn->query("
    SHOW TABLES LIKE 'report_requests'
");

if (
    $tableResult
    &&
    $tableResult->num_rows > 0
) {
    $reportTableExists = true;
}

if ($reportTableExists) {

    $reportStmt = $conn->prepare("
        SELECT
            id,
            job_id,
            owner_id,
            inspector_id,
            status,
            owner_message,
            report_path,
            requested_at,
            sent_at,
            completed_at

        FROM report_requests

        WHERE
            job_id = ?
            AND owner_id = ?

        ORDER BY id DESC

        LIMIT 1
    ");

    if ($reportStmt) {

        $reportStmt->bind_param(
            'ii',
            $jobId,
            $ownerId
        );

        if ($reportStmt->execute()) {

            $reportRequest =
                $reportStmt
                ->get_result()
                ->fetch_assoc();
        }

        $reportStmt->close();
    }
}

$reportStatus = strtolower(
    trim(
        (string)(
            $reportRequest['status']
            ?? ''
        )
    )
);


/* =========================================================
   SERVICE COMPLETION / CERTIFICATE STATUS
   ---------------------------------------------------------
   PAT:
       completion is stored in checklist.pat_status
       maintenance_jobs.status = closed
       sat_status = unlocked

   SAT:
       completion is stored in checklist.sat_status
       maintenance_jobs.status = closed
       sat_status = closed

   Certificate request is available after the applicable
   service is closed.
========================================================= */

$serviceStatusKey = $isSci
    ? 'sci_status'
    : ($isSat ? 'sat_status' : 'pat_status');

$serviceCompletedAtKey = $isSci
    ? 'sci_completed_at_ist'
    : ($isSat ? 'sat_completed_at_ist' : 'pat_completed_at_ist');

$serviceStatus = strtolower(
    trim(
        (string)(
            $checklist[$serviceStatusKey]
            ?? ''
        )
    )
);

$serviceCompletedAt = (string)(
    $checklist[$serviceCompletedAtKey]
    ?? ''
);

/*
 * Backward-compatible variables used by the existing page.
 * They now represent the currently selected service (PAT, SAT or SCI).
 */
$patStatus = $serviceStatus;
$patCompletedAt = $serviceCompletedAt;

$certificateStatus = strtolower(
    trim(
        (string)(
            $job['certificate_status']
            ?? ''
        )
    )
);

$certificateNo = (string)(
    $job['certificate_no']
    ?? ''
);

$certificateGeneratedAt = (string)(
    $job['certificate_generated_at']
    ?? ''
);


/* =========================================================
   OWNER ACTIONS
   ---------------------------------------------------------
   The same Owner page handles PAT and SAT.

   PAT Complete:
       - final report must be sent
       - maintenance_jobs.status = closed
       - sat_status = unlocked
       - checklist.pat_status = closed

   SAT Complete:
       - final report must be sent
       - maintenance_jobs.status = closed
       - sat_status = closed
       - checklist.sat_status = closed

   Certificate:
       - applicable service must already be closed
       - certificate_status = requested
========================================================= */

$actionError = '';
$actionSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = strtolower(
        trim(
            (string)(
                $_POST['owner_action']
                ?? ''
            )
        )
    );

    /*
     * Accept the new generic action plus the old PAT action so
     * existing browser forms/bookmarks do not break.
     */
    /* =========================================================
       OWNER RESPONSIBILITY CONFIRMATION — ONE TIME PER INSPECTION
       ---------------------------------------------------------
       A single Owner confirmation covers ALL NC + CA checkpoints
       in this inspection. One supporting document is submitted
       with that confirmation.
    ========================================================== */
    if ($action === 'owner_responsibility_confirm') {

        $postedJobId = (int)($_POST['job_id'] ?? 0);

        $responsibilityConfirmed =
            isset($_POST['owner_responsibility_confirm'])
            && (string)$_POST['owner_responsibility_confirm'] === '1';

        if (
            !function_exists('validateCSRFToken')
            || !validateCSRFToken($_POST['csrf_token'] ?? '')
        ) {
            $_SESSION['owner_pat_error'] =
                'Invalid security token. Please refresh the page and try again.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        if ($postedJobId !== $jobId) {
            $_SESSION['owner_pat_error'] = 'Invalid inspection selected.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        if (!$responsibilityConfirmed) {
            $_SESSION['owner_pat_error'] =
                'Please confirm Owner responsibility before submitting.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        $upload = $_FILES['owner_compliance_document'] ?? null;

        if (
            !is_array($upload)
            || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            $_SESSION['owner_pat_error'] =
                'One supporting rectification/compliance document is required.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        $maxUploadBytes = 7 * 1024 * 1024;

        if ((int)($upload['size'] ?? 0) <= 0) {
            $_SESSION['owner_pat_error'] = 'The uploaded document is empty.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        if ((int)$upload['size'] > $maxUploadBytes) {
            $_SESSION['owner_pat_error'] =
                'Document size must not exceed 7 MB.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        $tmpFile = (string)($upload['tmp_name'] ?? '');

        if ($tmpFile === '' || !is_uploaded_file($tmpFile)) {
            $_SESSION['owner_pat_error'] = 'Invalid uploaded document.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        $allowedMimes = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string)$finfo->file($tmpFile);

        if (!isset($allowedMimes[$detectedMime])) {
            $_SESSION['owner_pat_error'] =
                'Invalid document type. Please upload PDF, JPG/JPEG or PNG only.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        $extension = $allowedMimes[$detectedMime];

        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("
                SELECT id, owner_id, checklist
                FROM maintenance_jobs
                WHERE id = ?
                  AND owner_id = ?
                  AND job_type = 'saf_inspection'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$stmt) {
                throw new RuntimeException(
                    'Unable to verify inspection: ' . $conn->error
                );
            }

            $stmt->bind_param('ii', $jobId, $ownerId);

            if (!$stmt->execute()) {
                throw new RuntimeException(
                    'Unable to verify inspection: ' . $stmt->error
                );
            }

            $ownerJob = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$ownerJob) {
                throw new RuntimeException(
                    'Inspection job not found or access denied.'
                );
            }

            $checklistData = [];

            if (!empty($ownerJob['checklist'])) {
                $decoded = json_decode(
                    (string)$ownerJob['checklist'],
                    true
                );

                if (is_array($decoded)) {
                    $checklistData = $decoded;
                }
            }

            /*
             * ONE-TIME FINAL CONFIRMATION LOCK
             * --------------------------------
             * Once Owner Responsibility Confirmation has been
             * confirmed for this inspection, it is FINAL.
             *
             * No second supporting document may be uploaded and
             * the existing confirmation/document must not be
             * replaced or overwritten.
             */
            $existingOwnerConfirmation = (
                isset($checklistData['owner_responsibility_confirmation'])
                && is_array($checklistData['owner_responsibility_confirmation'])
            )
                ? $checklistData['owner_responsibility_confirmation']
                : [];

            $existingConfirmationFinal =
                (($existingOwnerConfirmation['status'] ?? '') === 'confirmed')
                || (($existingOwnerConfirmation['owner_responsibility_confirmed'] ?? false) === true);

            if ($existingConfirmationFinal) {
                throw new RuntimeException(
                    'Owner Responsibility Confirmation has already been finally approved for this inspection. A second document upload is not permitted.'
                );
            }

            $uploadDir = dirname(__DIR__) . '/uploads/owner_compliance';

            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException(
                        'Unable to create Owner compliance document folder.'
                    );
                }
            }

            $filename =
                'OWNER-RESPONSIBILITY-'
                . $jobId
                . '-'
                . date('YmdHis')
                . '-'
                . bin2hex(random_bytes(4))
                . '.'
                . $extension;

            $destination = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($tmpFile, $destination)) {
                throw new RuntimeException(
                    'Unable to save the Owner compliance document.'
                );
            }

            $storedDocument =
                'uploads/owner_compliance/' . $filename;

            /*
             * This is the single final confirmation record.
             * There is intentionally NO document_history and NO
             * replacement path because a confirmed document is
             * immutable for this inspection.
             */
            $nowIst = date('Y-m-d H:i:s');

            $checklistData['owner_responsibility_confirmation'] = [
                'status' => 'confirmed',
                'owner_id' => $ownerId,
                'owner_responsibility_confirmed' => true,
                'rectification_commitment' => true,
                'covers_results' => ['NC', 'CA'],
                'document' => $storedDocument,
                'document_name' => (string)($upload['name'] ?? $filename),
                'document_mime' => $detectedMime,
                'document_size' => (int)$upload['size'],
                'confirmed_at_ist' => $nowIst,
                'timezone' => 'Asia/Kolkata',
                'final' => true,
                'document_locked' => true,
            ];

            $json = json_encode(
                $checklistData,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            );

            if ($json === false) {
                throw new RuntimeException(
                    'Unable to save Owner responsibility confirmation.'
                );
            }

            $save = $conn->prepare("
                UPDATE maintenance_jobs
                SET checklist = ?, updated_at = NOW()
                WHERE id = ?
                  AND owner_id = ?
                  AND job_type = 'saf_inspection'
                LIMIT 1
            ");

            if (!$save) {
                throw new RuntimeException(
                    'Unable to prepare confirmation save: ' . $conn->error
                );
            }

            $save->bind_param('sii', $json, $jobId, $ownerId);

            if (!$save->execute()) {
                throw new RuntimeException(
                    'Unable to save Owner responsibility confirmation: '
                    . $save->error
                );
            }

            $save->close();
            $conn->commit();

            $_SESSION['owner_pat_success'] =
                'Owner responsibility confirmed once for all NC and CA checkpoints, and the supporting document was submitted successfully.';

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }

            error_log(
                'NLETA Owner Responsibility Confirmation Error: '
                . $e->getMessage()
            );

            $_SESSION['owner_pat_error'] = $e->getMessage();
        }

        header(
            'Location: ' . $_SERVER['SCRIPT_NAME']
            . '?job_id=' . rawurlencode((string)$jobId),
            true,
            303
        );
        exit;
    }

    /* =========================================================
       OWNER CONDITIONAL ACCEPTANCE DECISION
       ---------------------------------------------------------
       CA is a checkpoint-level permission. The Owner can approve
       or reject the CA proposed by the Inspector.

       IMPORTANT:
       - This does NOT change the overall checklist/job status.
       - It records a permanent decision inside the checklist JSON.
       - Approval is the proof that the Owner permitted this CA.
       - Reject requires a reason.
    ========================================================== */
    if (
        $action === 'ca_approve'
        || $action === 'ca_reject'
    ) {

        $postedJobId = (int)(
            $_POST['job_id'] ?? 0
        );

        $caRef = trim(
            (string)(
                $_POST['ca_ref'] ?? ''
            )
        );

        $caFloor = trim(
            (string)(
                $_POST['ca_floor'] ?? ''
            )
        );

        $caMatrix = (
            isset($_POST['ca_matrix'])
            && (string)$_POST['ca_matrix'] === '1'
        );

        $caRejectReason = trim(
            (string)(
                $_POST['ca_reject_reason'] ?? ''
            )
        );

        if (
            !function_exists('validateCSRFToken')
            ||
            !validateCSRFToken(
                $_POST['csrf_token'] ?? ''
            )
        ) {
            $_SESSION['owner_pat_error'] =
                'Invalid security token. Please refresh the page and try again.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        if ($postedJobId !== $jobId || $caRef === '') {
            $_SESSION['owner_pat_error'] =
                'Invalid Conditional Acceptance checkpoint selected.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        if (
            $action === 'ca_reject'
            && $caRejectReason === ''
        ) {
            $_SESSION['owner_pat_error'] =
                'Please enter a reason before rejecting this Conditional Acceptance.';
            header(
                'Location: ' . $_SERVER['SCRIPT_NAME']
                . '?job_id=' . rawurlencode((string)$jobId),
                true,
                303
            );
            exit;
        }

        try {

            $conn->begin_transaction();

            $caStmt = $conn->prepare("
                SELECT
                    id,
                    owner_id,
                    checklist,
                    status
                FROM maintenance_jobs
                WHERE
                    id = ?
                    AND owner_id = ?
                    AND job_type = 'saf_inspection'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$caStmt) {
                throw new RuntimeException(
                    'Unable to verify CA checkpoint: ' . $conn->error
                );
            }

            $caStmt->bind_param(
                'ii',
                $jobId,
                $ownerId
            );

            if (!$caStmt->execute()) {
                throw new RuntimeException(
                    'Unable to verify CA checkpoint: ' . $caStmt->error
                );
            }

            $caJob = $caStmt
                ->get_result()
                ->fetch_assoc();

            $caStmt->close();

            if (!$caJob) {
                throw new RuntimeException(
                    'Inspection job not found or access denied.'
                );
            }

            $caChecklist = [];

            if (!empty($caJob['checklist'])) {
                $caDecoded = json_decode(
                    (string)$caJob['checklist'],
                    true
                );

                if (is_array($caDecoded)) {
                    $caChecklist = $caDecoded;
                }
            }

            /*
             * Resolve the CA target from the same structure in which
             * the Inspector saved it:
             *   - SAT matrix row      -> checklist['matrices'][ref]
             *   - floor-wise row      -> floor_checklist/floor_results
             *   - fixed checkpoint    -> checklist['results'][ref]
             */
            if ($caMatrix) {

                if (
                    !isset($caChecklist['matrices'][$caRef])
                    ||
                    !is_array($caChecklist['matrices'][$caRef])
                ) {
                    throw new RuntimeException(
                        'Conditional Acceptance SAT matrix checkpoint was not found.'
                    );
                }

                $targetItem =& $caChecklist['matrices'][$caRef];

            } else {

                $targetItem =& $caChecklist['results'][$caRef];

                if ($caFloor !== '') {
                    $floorContainers = [
                        'floor_checklist',
                        'floor_results'
                    ];

                    $foundFloor = false;

                    foreach ($floorContainers as $floorContainer) {
                        if (
                            isset($caChecklist[$floorContainer][$caFloor][$caRef])
                            &&
                            is_array(
                                $caChecklist[$floorContainer][$caFloor][$caRef]
                            )
                        ) {
                            $targetItem =&
                                $caChecklist[$floorContainer][$caFloor][$caRef];
                            $foundFloor = true;
                            break;
                        }
                    }

                    if (!$foundFloor) {
                        throw new RuntimeException(
                            'Conditional Acceptance floor checkpoint was not found.'
                        );
                    }
                } else {
                    if (
                        !isset($caChecklist['results'][$caRef])
                        ||
                        !is_array($caChecklist['results'][$caRef])
                    ) {
                        throw new RuntimeException(
                            'Conditional Acceptance checkpoint was not found.'
                        );
                    }
                }
            }

            $targetResult = strtoupper(
                trim(
                    (string)(
                        $targetItem['result'] ?? ''
                    )
                )
            );

            if ($targetResult !== 'CA') {
                throw new RuntimeException(
                    'Only a checkpoint currently marked CA can receive an Owner CA decision.'
                );
            }

            $existingDecision = (
                isset($targetItem['owner_ca_decision'])
                &&
                is_array($targetItem['owner_ca_decision'])
            )
                ? $targetItem['owner_ca_decision']
                : [];

            $decisionStatus = (
                $action === 'ca_approve'
            )
                ? 'approved'
                : 'rejected';

            /*
             * Do not silently overwrite an already approved CA.
             * A rejected CA can be re-decided only if the Inspector
             * later changes the checkpoint and saves it again.
             */
            if (
                ($existingDecision['status'] ?? '') === 'approved'
                &&
                $decisionStatus === 'approved'
            ) {
                throw new RuntimeException(
                    'This Conditional Acceptance is already approved by the Owner.'
                );
            }

            $nowIst = date('Y-m-d H:i:s');

            $targetItem['owner_ca_decision'] = [
                'status' => $decisionStatus,
                'owner_id' => $ownerId,
                'decided_at_ist' => $nowIst,
                'timezone' => 'Asia/Kolkata'
            ];

            if ($decisionStatus === 'approved') {
                $targetItem['owner_ca_decision']['decision_text'] =
                    'Owner Approved — Conditional Acceptance permitted.';
                $targetItem['owner_ca_decision']['reason'] = '';
            } else {
                $targetItem['owner_ca_decision']['decision_text'] =
                    'Owner Rejected — Conditional Acceptance not permitted.';
                $targetItem['owner_ca_decision']['reason'] =
                    $caRejectReason;
            }

            unset($targetItem);

            $caJson = json_encode(
                $caChecklist,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
            );

            if ($caJson === false) {
                throw new RuntimeException(
                    'Unable to save Owner Conditional Acceptance decision.'
                );
            }

            $saveCaStmt = $conn->prepare("
                UPDATE maintenance_jobs
                SET
                    checklist = ?,
                    updated_at = NOW()
                WHERE
                    id = ?
                    AND owner_id = ?
                    AND job_type = 'saf_inspection'
                LIMIT 1
            ");

            if (!$saveCaStmt) {
                throw new RuntimeException(
                    'Unable to prepare Owner CA decision save: '
                    . $conn->error
                );
            }

            $saveCaStmt->bind_param(
                'sii',
                $caJson,
                $jobId,
                $ownerId
            );

            if (!$saveCaStmt->execute()) {
                throw new RuntimeException(
                    'Unable to save Owner CA decision: '
                    . $saveCaStmt->error
                );
            }

            if ($saveCaStmt->affected_rows < 1) {
                throw new RuntimeException(
                    'Owner CA decision could not be saved.'
                );
            }

            $saveCaStmt->close();

            $conn->commit();

            $_SESSION['owner_pat_success'] =
                $decisionStatus === 'approved'
                    ? 'Conditional Acceptance approved by Owner. This approval is now recorded as proof of Owner permission for this checkpoint.'
                    : 'Conditional Acceptance rejected by Owner and the rejection reason has been recorded.';

        } catch (Throwable $e) {

            try {
                $conn->rollback();
            } catch (Throwable $ignore) {
            }

            error_log(
                'NLETA Owner CA Decision Error: '
                . $e->getMessage()
            );

            $_SESSION['owner_pat_error'] =
                $e->getMessage();
        }

        header(
            'Location: ' . $_SERVER['SCRIPT_NAME']
            . '?job_id=' . rawurlencode((string)$jobId),
            true,
            303
        );
        exit;
    }

    $isCompleteAction =
        $action === 'service_complete'
        || $action === 'pat_complete';

    if ($isCompleteAction || $action === 'request_certificate') {

        if (
            !function_exists('validateCSRFToken')
            ||
            !validateCSRFToken(
                $_POST['csrf_token'] ?? ''
            )
        ) {

            $actionError =
                'Invalid request. Please refresh the page and try again.';

        } else {

            $postedJobId = (int)(
                $_POST['job_id'] ?? 0
            );

            if ($postedJobId !== $jobId) {

                $actionError =
                    'Invalid inspection job selected.';

            } else {

                try {

                    /*
                     * Re-read the current service/job immediately before
                     * changing anything. This prevents stale page data.
                     */
                    $lockStmt = $conn->prepare("
                        SELECT
                            id,
                            owner_id,
                            asset_id,
                            status,
                            checklist,
                            certificate_status,
                            sat_status
                        FROM maintenance_jobs
                        WHERE
                            id = ?
                            AND owner_id = ?
                            AND job_type = 'saf_inspection'
                        LIMIT 1
                    ");

                    if (!$lockStmt) {
                        throw new RuntimeException(
                            'Unable to verify service action: '
                            . $conn->error
                        );
                    }

                    $lockStmt->bind_param(
                        'ii',
                        $jobId,
                        $ownerId
                    );

                    if (!$lockStmt->execute()) {
                        throw new RuntimeException(
                            'Unable to verify service action: '
                            . $lockStmt->error
                        );
                    }

                    $freshJob = $lockStmt
                        ->get_result()
                        ->fetch_assoc();

                    $lockStmt->close();

                    if (!$freshJob) {
                        throw new RuntimeException(
                            'Inspection service not found or access denied.'
                        );
                    }

                    $freshChecklist = [];

                    if (!empty($freshJob['checklist'])) {

                        $decodedFresh = json_decode(
                            $freshJob['checklist'],
                            true
                        );

                        if (is_array($decodedFresh)) {
                            $freshChecklist = $decodedFresh;
                        }
                    }

                    $freshCertificateStatus = strtolower(
                        trim(
                            (string)(
                                $freshJob['certificate_status']
                                ?? ''
                            )
                        )
                    );

                    /*
                     * Determine service from the actual database-linked
                     * contract/checklist, not from the button label.
                     */
                    $freshServiceCode = strtoupper(
                        trim(
                            (string)(
                                $job['service_code']
                                ?? $freshChecklist['service_code']
                                ?? ''
                            )
                        )
                    );

                    $freshModule = strtoupper(
                        trim(
                            (string)(
                                $freshChecklist['module']
                                ?? ''
                            )
                        )
                    );

                    $freshIsSci =
                        $freshServiceCode === 'SCI'
                        || $freshModule === 'SCI';

                    $freshIsSat =
                        !$freshIsSci
                        && (
                            $freshServiceCode === 'SAT'
                            || $freshModule === 'SAT'
                        );

                    $freshIsPat =
                        !$freshIsSci
                        && !$freshIsSat;

                    $freshServiceLabel =
                        $freshIsSci
                            ? 'SCI'
                            : ($freshIsSat ? 'SAT' : 'PAT');

                    $freshStatusKey =
                        $freshIsSci
                            ? 'sci_status'
                            : ($freshIsSat ? 'sat_status' : 'pat_status');

                    $freshCompletedAtKey =
                        $freshIsSci
                            ? 'sci_completed_at_ist'
                            : ($freshIsSat ? 'sat_completed_at_ist' : 'pat_completed_at_ist');


                    /* =============================================
                       SERVICE COMPLETE
                    ============================================== */

                    if ($isCompleteAction) {

                        /*
                         * Final report is mandatory before either PAT
                         * or SAT can be closed.
                         */
                        if ($reportTableExists === false) {
                            throw new RuntimeException(
                                'The report request module is not configured. The final inspection report must be sent before closing the service.'
                            );
                        }

                        $freshReportStmt = $conn->prepare("
                            SELECT
                                id,
                                status
                            FROM report_requests
                            WHERE
                                job_id = ?
                                AND owner_id = ?
                            ORDER BY id DESC
                            LIMIT 1
                        ");

                        if (!$freshReportStmt) {
                            throw new RuntimeException(
                                'Unable to verify final report: '
                                . $conn->error
                            );
                        }

                        $freshReportStmt->bind_param(
                            'ii',
                            $jobId,
                            $ownerId
                        );

                        if (!$freshReportStmt->execute()) {
                            throw new RuntimeException(
                                'Unable to verify final report: '
                                . $freshReportStmt->error
                            );
                        }

                        $freshReport = $freshReportStmt
                            ->get_result()
                            ->fetch_assoc();

                        $freshReportStmt->close();

                        if (!$freshReport) {
                            throw new RuntimeException(
                                'The final inspection report has not been sent to the Owner yet.'
                            );
                        }

                        $freshReportStatus = strtolower(
                            trim(
                                (string)(
                                    $freshReport['status']
                                    ?? ''
                                )
                            )
                        );

                        if (!in_array(
                            $freshReportStatus,
                            [
                                'sent_to_owner',
                                'sent',
                                'completed',
                                'generated'
                            ],
                            true
                        )) {
                            throw new RuntimeException(
                                $freshServiceLabel
                                . ' cannot be closed until the Inspector sends the final inspection report to the Owner.'
                            );
                        }

                        $currentFreshServiceStatus = strtolower(
                            trim(
                                (string)(
                                    $freshChecklist[$freshStatusKey]
                                    ?? ''
                                )
                            )
                        );

                        if (
                            $currentFreshServiceStatus === 'closed'
                            ||
                            strtolower(
                                trim(
                                    (string)(
                                        $freshJob['status']
                                        ?? ''
                                    )
                                )
                            ) === 'closed'
                        ) {

                            /*
                             * Already closed. Do not write duplicate
                             * completion data.
                             */
                            $actionSuccess =
                                $freshServiceLabel
                                . ' is already closed.';

                        } else {

                            $freshChecklist[$freshStatusKey] =
                                'closed';

                            $freshChecklist['final_status'] =
                                'completed';

                            $freshChecklist[$freshCompletedAtKey] =
                                date('Y-m-d H:i:s');

                            $freshChecklist[
                                $freshIsSci
                                    ? 'sci_completed_timezone'
                                    : ($freshIsSat ? 'sat_completed_timezone' : 'pat_completed_timezone')
                            ] = 'Asia/Kolkata';

                            $freshChecklist[
                                $freshIsSci
                                    ? 'sci_completed_by_owner_id'
                                    : ($freshIsSat ? 'sat_completed_by_owner_id' : 'pat_completed_by_owner_id')
                            ] = $ownerId;

                            $freshJson = json_encode(
                                $freshChecklist,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                                | JSON_PRETTY_PRINT
                            );

                            if ($freshJson === false) {
                                throw new RuntimeException(
                                    'Unable to save '
                                    . $freshServiceLabel
                                    . ' completion information.'
                                );
                            }

                            $conn->begin_transaction();

                            try {

                                /*
                                 * IMPORTANT:
                                 * bind_param types are:
                                 *   s = checklist JSON
                                 *   i = job ID
                                 *   i = owner ID
                                 *   s = service code
                                 *
                                 * Previous code used "siis", which made
                                 * $ownerId be treated as a string and
                                 * caused the close query to fail.
                                 */
                                $closeStmt = $conn->prepare("
                                    UPDATE maintenance_jobs
                                    SET
                                        checklist = ?,
                                        status = 'closed',
                                        sat_status = CASE
                                            WHEN UPPER(TRIM(?)) = 'PAT'
                                                THEN 'unlocked'
                                            WHEN UPPER(TRIM(?)) = 'SAT'
                                                THEN 'closed'
                                            WHEN UPPER(TRIM(?)) = 'SCI'
                                                THEN 'closed'
                                            ELSE sat_status
                                        END,
                                        updated_at = NOW()
                                    WHERE
                                        id = ?
                                        AND owner_id = ?
                                        AND job_type = 'saf_inspection'
                                    LIMIT 1
                                ");

                                if (!$closeStmt) {
                                    throw new RuntimeException(
                                        'Unable to prepare '
                                        . $freshServiceLabel
                                        . ' close action: '
                                        . $conn->error
                                    );
                                }

                                $serviceCodeForClose =
                                    $freshIsSci
                                        ? 'SCI'
                                        : ($freshIsSat ? 'SAT' : 'PAT');

                                /*
                                 * Correct parameter order:
                                 * checklist, serviceCode, serviceCode,
                                 * jobId, ownerId
                                 */
                                $closeStmt->bind_param(
                                    'ssssii',
                                    $freshJson,
                                    $serviceCodeForClose,
                                    $serviceCodeForClose,
                                    $serviceCodeForClose,
                                    $jobId,
                                    $ownerId
                                );

                                if (!$closeStmt->execute()) {
                                    throw new RuntimeException(
                                        'Unable to close '
                                        . $freshServiceLabel
                                        . ': '
                                        . $closeStmt->error
                                    );
                                }

                                if ($closeStmt->affected_rows < 1) {
                                    throw new RuntimeException(
                                        $freshServiceLabel
                                        . ' could not be closed.'
                                    );
                                }

                                $closeStmt->close();

                                $conn->commit();

                                $actionSuccess =
                                    $freshServiceLabel
                                    . ' completed and closed successfully.';

                            } catch (Throwable $e) {

                                $conn->rollback();

                                throw $e;
                            }
                        }


                    /* =============================================
                       REQUEST CERTIFICATE
                    ============================================== */

                    } elseif ($action === 'request_certificate') {

                        /*
                         * Certificate request is available only after
                         * the currently selected service is closed.
                         */
                        $freshServiceStatus = strtolower(
                            trim(
                                (string)(
                                    $freshChecklist[$freshStatusKey]
                                    ?? ''
                                )
                            )
                        );

                        if (
                            $freshServiceStatus !== 'closed'
                            &&
                            strtolower(
                                trim(
                                    (string)(
                                        $freshJob['status']
                                        ?? ''
                                    )
                                )
                            ) !== 'closed'
                        ) {
                            throw new RuntimeException(
                                'Please click '
                                . $freshServiceLabel
                                . ' Complete before requesting the certificate.'
                            );
                        }

                        if ($freshCertificateStatus === 'requested') {

                            $actionSuccess =
                                'Certificate request has already been sent to Admin.';

                        } elseif (
                            $freshCertificateStatus === 'generated'
                            ||
                            $freshCertificateStatus === 'sent'
                        ) {

                            $actionSuccess =
                                'Certificate has already been processed.';

                        } else {

                            $freshChecklist['certificate_request'] = [
                                'status' => 'requested',
                                'requested_at_ist' => date('Y-m-d H:i:s'),
                                'timezone' => 'Asia/Kolkata',
                                'requested_by_owner_id' => $ownerId,
                                'service_code' => $freshIsSci ? 'SCI' : ($freshIsSat ? 'SAT' : 'PAT')
                            ];

                            $freshJson = json_encode(
                                $freshChecklist,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                                | JSON_PRETTY_PRINT
                            );

                            if ($freshJson === false) {
                                throw new RuntimeException(
                                    'Unable to save certificate request information.'
                                );
                            }

                            $conn->begin_transaction();

                            try {

                                $certificateStmt = $conn->prepare("
                                    UPDATE maintenance_jobs
                                    SET
                                        checklist = ?,
                                        certificate_status = 'requested',
                                        updated_at = NOW()
                                    WHERE
                                        id = ?
                                        AND owner_id = ?
                                        AND job_type = 'saf_inspection'
                                        AND status = 'closed'
                                    LIMIT 1
                                ");

                                if (!$certificateStmt) {
                                    throw new RuntimeException(
                                        'Unable to prepare certificate request: '
                                        . $conn->error
                                    );
                                }

                                $certificateStmt->bind_param(
                                    'sii',
                                    $freshJson,
                                    $jobId,
                                    $ownerId
                                );

                                if (!$certificateStmt->execute()) {
                                    throw new RuntimeException(
                                        'Unable to request certificate: '
                                        . $certificateStmt->error
                                    );
                                }

                                if ($certificateStmt->affected_rows < 1) {
                                    throw new RuntimeException(
                                        'Certificate request could not be saved. Make sure the service is closed.'
                                    );
                                }

                                $certificateStmt->close();

                                $conn->commit();

                                $actionSuccess =
                                    'Certificate request sent to Admin successfully.';

                            } catch (Throwable $e) {

                                $conn->rollback();

                                throw $e;
                            }
                        }
                    }

                } catch (Throwable $e) {

                    $actionError = $e->getMessage();
                }
            }
        }

        /*
         * PRG redirect after POST.
         */
        if ($actionSuccess !== '') {

            $_SESSION['owner_pat_success'] =
                $actionSuccess;

            $redirectUrl =
                $_SERVER['SCRIPT_NAME']
                . '?job_id='
                . rawurlencode((string)$jobId);

            header(
                'Location: ' . $redirectUrl,
                true,
                303
            );

            exit;
        }

        if ($actionError !== '') {

            $_SESSION['owner_pat_error'] =
                $actionError;

            $redirectUrl =
                $_SERVER['SCRIPT_NAME']
                . '?job_id='
                . rawurlencode((string)$jobId);

            header(
                'Location: ' . $redirectUrl,
                true,
                303
            );

            exit;
        }
    }
}


/* =========================================================
   SESSION ACTION MESSAGES
========================================================= */

if (
    !empty(
        $_SESSION[
            'owner_pat_success'
        ]
    )
) {

    $actionSuccess =
        (string)(
            $_SESSION[
                'owner_pat_success'
            ]
        );

    unset(
        $_SESSION[
            'owner_pat_success'
        ]
    );
}

if (
    !empty(
        $_SESSION[
            'owner_pat_error'
        ]
    )
) {

    $actionError =
        (string)(
            $_SESSION[
                'owner_pat_error'
            ]
        );

    unset(
        $_SESSION[
            'owner_pat_error'
        ]
    );
}


/* =========================================================
   REFRESH ACTION STATUS FROM CURRENT PAGE DATA
   ---------------------------------------------------------
   IMPORTANT:
   This page supports BOTH PAT and SAT.

   Do NOT read pat_status unconditionally here.
   SAT completion is stored in checklist.sat_status, while
   PAT completion is stored in checklist.pat_status.

   The old code always refreshed $patStatus from pat_status.
   Therefore a CLOSED SAT could be displayed as:
       "SAT is already closed"
   and at the same time:
       "SAT is not closed yet"
   with the SAT Complete button still visible.

   Use the currently selected service status consistently.
========================================================= */

$serviceStatusKey = $isSci
    ? 'sci_status'
    : ($isSat ? 'sat_status' : 'pat_status');

$serviceCompletedAtKey = $isSci
    ? 'sci_completed_at_ist'
    : ($isSat ? 'sat_completed_at_ist' : 'pat_completed_at_ist');

$serviceStatus = strtolower(
    trim(
        (string)(
            $checklist[$serviceStatusKey]
            ?? ''
        )
    )
);

$serviceCompletedAt = (string)(
    $checklist[$serviceCompletedAtKey]
    ?? ''
);

/*
 * Backward-compatible variables used by the existing HTML below.
 * These now ALWAYS represent the currently selected service.
 *
 * PAT job  -> $patStatus = checklist.pat_status
 * SAT job  -> $patStatus = checklist.sat_status
 * SCI job  -> $patStatus = checklist.sci_status
 */
$patStatus = $serviceStatus;
$patCompletedAt = $serviceCompletedAt;

$certificateStatus = strtolower(
    trim(
        (string)(
            $job['certificate_status']
            ?? ''
        )
    )
);

$certificateNo = (string)(
    $job['certificate_no']
    ?? ''
);

$certificateGeneratedAt = (string)(
    $job['certificate_generated_at']
    ?? ''
);


/* =========================================================
   REPORT FILE
========================================================= */

$reportFileUrl = '';

if (
    $reportRequest
    &&
    !empty(
        $reportRequest['report_path']
    )
) {

    $storedReport =
        str_replace(
            '\\',
            '/',
            trim(
                (string)(
                    $reportRequest[
                        'report_path'
                    ]
                )
            )
        );

    if (
        preg_match(
            '#^https?://#i',
            $storedReport
        )
    ) {

        $reportFileUrl =
            $storedReport;

    } else {

        $reportFileUrl =
            evidenceUrl(
                $storedReport
            );
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>


<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #f4f6f9;
    font-family: "Segoe UI", Arial, sans-serif;
    color: #172033;
}

.page {
    width: 100%;
    margin: 0 auto;
    padding: 22px 28px 50px;
}

.card-box {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,.07);
    margin-bottom: 18px;
    overflow: hidden;
}

.card-title {
    padding: 15px 20px;
    background: #202428;
    color: #fff;
    font-size: 17px;
    font-weight: 600;
}

.info-grid {
    padding: 16px;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.info-item {
    border: 1px solid #e4e8ed;
    border-radius: 8px;
    padding: 12px;
    background: #fafafa;
}

.info-label {
    font-size: 11px;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.info-value {
    font-weight: 600;
    font-size: 14px;
}

.summary-grid {
    padding: 16px;
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.summary {
    border: 1px solid #e4e8ed;
    border-radius: 8px;
    padding: 13px;
    text-align: center;
    background: #fafafa;
}

.summary strong {
    display: block;
    font-size: 22px;
    margin-top: 3px;
}

.summary small {
    color: #64748b;
    font-size: 11px;
}

.summary-nc {
    color: #dc3545;
}

.summary-ca {
    color: #b7791f;
}

.summary-photo {
    color: #0d6efd;
}

.summary-video {
    color: #6f42c1;
}

.status {
    display: inline-block;
    padding: 7px 12px;
    border-radius: 18px;
    font-size: 12px;
    font-weight: 700;
}

.status.pending {
    background: #fff1c2;
    color: #7a5200;
}

.status.approved {
    background: #d9f4e5;
    color: #0c6b35;
}

.status.rejected {
    background: #f9d9dc;
    color: #9b1c25;
}

.status.scheduled {
    background: #e7f1ff;
    color: #0d6efd;
}

.status.completed {
    background: #d9f4e5;
    color: #0c6b35;
}

.table-wrap {
    overflow-x: auto;
}

.checklist-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1050px;
}

.checklist-table th {
    background: #f1f3f5;
    border-bottom: 2px solid #dee2e6;
    padding: 10px;
    font-size: 12px;
    text-align: left;
    white-space: nowrap;
}

.checklist-table td {
    border-bottom: 1px solid #edf0f2;
    padding: 10px;
    vertical-align: top;
    font-size: 13px;
}

.checklist-table tbody tr:hover {
    background: #fbfcfe;
}

.nc-only-table,
.d-only-table {
    min-width: 1000px;
    height: auto;
}

.nc-only-table th,
.nc-only-table td,
.d-only-table th,
.d-only-table td {
    height: auto;
    min-height: 0;
}

.nc-only-table tbody tr,
.d-only-table tbody tr {
    height: auto;
}

.nc-only-table td,
.d-only-table td {
    white-space: normal;
    overflow-wrap: anywhere;
}

/* =========================================================
   CA TABLE — FIXED COLUMN LAYOUT
   ---------------------------------------------------------
   Keeps Remarks from becoming too narrow and prevents the
   Owner Decision column from pushing the other columns.
========================================================= */

.ca-table {
    width: 100%;
    min-width: 1250px;
    table-layout: fixed;
}

.ca-table .ca-col-checkpoint {
    width: 100px;
}

.ca-table .ca-col-priority {
    width: 90px;
}

.ca-table .ca-col-section {
    width: 180px;
    min-width: 180px;
    max-width: 180px;
    vertical-align: top;
}

.ca-table .ca-col-name {
    width: 270px;
}

.ca-table .ca-col-reading {
    width: 110px;
}

.ca-table .ca-col-result {
    width: 90px;
}

.ca-table .ca-col-remarks {
    width: 220px;
}

.ca-table .ca-col-evidence {
    width: 120px;
}

.ca-table .ca-col-owner {
    width: 230px;
}

.ca-table th,
.ca-table td {
    box-sizing: border-box;
}

.ca-table th {
    vertical-align: middle;
}

.ca-table td {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
}

.ca-table .remark-cell {
    max-width: none;
    width: 220px;
    line-height: 1.45;
}

.ca-table .evidence-links {
    max-width: 220px;
}

.ca-table .ca-owner-decision-cell {
    width: 230px;
    min-width: 230px;
    vertical-align: middle !important;
    white-space: normal;
    overflow-wrap: anywhere;
}

.ca-table .ca-action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
    align-items: center;
}

.ca-table .ca-approve-btn,
.ca-table .ca-reject-btn {
    flex: 0 0 auto;
}

@media (max-width: 900px) {
    .ca-table {
        min-width: 1180px;
    }
}

.no-nc-box {
    padding: 24px 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    color: #0c6b35;
    background: #eaf8ef;
    border-top: 1px solid #b7e4c7;
}

.no-nc-box i {
    font-size: 20px;
}

.no-nc-box strong {
    font-size: 14px;
}

.no-nc-box span {
    color: #495057;
    font-size: 13px;
}

.checkpoint-id {
    font-weight: 700;
    color: #0d6efd;
    white-space: nowrap;
}

.checkpoint-name {
    margin-top: 4px;
    color: #172033;
    font-size: 12px;
    line-height: 1.45;
    font-weight: 500;
    white-space: normal;
    overflow-wrap: anywhere;
}

.section-badge {
    display: inline-block;
    min-width: 42px;
    max-width: 100%;
    box-sizing: border-box;
    text-align: left;
    padding: 5px 9px;
    border-radius: 14px;
    background: #f1f3f5;
    color: #343a40;
    font-size: 11px;
    font-weight: 800;
    line-height: 1.35;
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: break-word;
}

/* =========================================================
   OWNER CA — RESPONSIVE SECTION COLUMN
   ---------------------------------------------------------
   Long saved section names must wrap inside the Section
   column and must never overlap Checkpoint Name.
========================================================= */
.ca-table td:nth-child(3),
.ca-table th:nth-child(3) {
    width: 180px;
    min-width: 180px;
    max-width: 180px;
    white-space: normal !important;
    vertical-align: top;
    overflow: hidden;
    overflow-wrap: anywhere;
    word-break: break-word;
}

.ca-table td:nth-child(3) .section-badge {
    display: block;
    width: 100%;
    min-width: 0;
    max-width: 100%;
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: break-word;
    text-align: left;
}

@media (max-width: 900px) {
    .ca-table td:nth-child(3),
    .ca-table th:nth-child(3) {
        width: 180px;
        min-width: 180px;
        max-width: 180px;
    }
}


.priority-badge {
    display: inline-block;
    min-width: 42px;
    text-align: center;
    padding: 5px 8px;
    border-radius: 14px;
    background: #e7f1ff;
    color: #0d6efd;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.result-badge {
    display: inline-block;
    min-width: 42px;
    text-align: center;
    padding: 5px 8px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 700;
}

.result-c {
    background: #d9f4e5;
    color: #0c6b35;
}

.result-nc {
    background: #f9d9dc;
    color: #9b1c25;
}

.result-na {
    background: #e9ecef;
    color: #495057;
}

.result-ca {
    background: #fff1c2;
    color: #7a5200;
}

.result-other {
    background: #e7f1ff;
    color: #0d6efd;
}

.evidence-links {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.evidence-link {
    text-decoration: none;
    border: 1px solid #d7dde5;
    border-radius: 6px;
    padding: 5px 8px;
    font-size: 11px;
    background: #fff;
}

.evidence-link:hover {
    background: #f4f7fb;
}

.empty-evidence {
    color: #9ca3af;
    font-size: 12px;
}

.remark-cell {
    white-space: pre-wrap;
    max-width: 320px;
    overflow-wrap: anywhere;
}

.justification-cell {
    white-space: pre-wrap;
    max-width: 320px;
    overflow-wrap: anywhere;
}


/* =========================================================
   APPROVAL / REPORT
========================================================= */

.approval-box {
    padding: 18px 20px;
}

.approval-message {
    background: #f8f9fa;
    border-left: 4px solid #0d6efd;
    padding: 12px 14px;
    margin-bottom: 15px;
    white-space: pre-wrap;
}

.report-request-box {
    padding: 20px;
}

.report-request-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.report-request-title {
    font-size: 16px;
    font-weight: 700;
}

.report-request-description {
    color: #6c757d;
    font-size: 13px;
    margin-top: 3px;
}

.report-request-btn {
    border: none;
    border-radius: 8px;
    padding: 10px 17px;
    background: #0d6efd;
    color: #fff;
    font-weight: 600;
    white-space: nowrap;
}

.report-request-btn:hover {
    background: #0b5ed7;
}

.report-request-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.report-status-box {
    border-radius: 10px;
    padding: 14px 16px;
}

.report-status-requested {
    background: #fff8e1;
    border: 1px solid #ffe69c;
}

.report-status-progress {
    background: #eef6ff;
    border: 1px solid #b6d7ff;
}

.report-status-sent {
    background: #eaf8ef;
    border: 1px solid #b7e4c7;
}

.report-status-cancelled {
    background: #f1f3f5;
    border: 1px solid #dee2e6;
}

.report-status-title {
    font-weight: 700;
    margin-bottom: 4px;
}

.report-status-text {
    color: #495057;
    font-size: 13px;
}

.view-report-btn {
    display: inline-block;
    margin-top: 12px;
    padding: 9px 15px;
    border-radius: 7px;
    background: #198754;
    color: #fff;
    text-decoration: none;
    font-weight: 600;
}

.view-report-btn:hover {
    background: #146c43;
    color: #fff;
}

/* =========================================================
   SERVICE COMPLETION / CERTIFICATE ACTIONS
========================================================= */

.action-panel {
    padding: 20px;
}

.action-status {
    padding: 14px 16px;
    border-radius: 10px;
    border: 1px solid #dee2e6;
    background: #f8f9fa;
    margin-bottom: 14px;
}

.action-status.closed {
    background: #eaf8ef;
    border-color: #b7e4c7;
    color: #0c6b35;
}

.action-status.requested {
    background: #fff8e1;
    border-color: #ffe69c;
    color: #7a5200;
}

.action-status.generated {
    background: #eef6ff;
    border-color: #b6d7ff;
    color: #0d6efd;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.pat-action-btn {
    border: none;
    border-radius: 8px;
    padding: 10px 17px;
    font-weight: 600;
    cursor: pointer;
}

.pat-complete-btn {
    background: #198754;
    color: #fff;
}

.pat-complete-btn:hover {
    background: #146c43;
}

.certificate-request-btn {
    background: #0d6efd;
    color: #fff;
}

.certificate-request-btn:hover {
    background: #0b5ed7;
}

.pat-action-btn:disabled {
    opacity: .55;
    cursor: not-allowed;
}

.action-help {
    margin-top: 10px;
    color: #6c757d;
    font-size: 12px;
}

.action-meta {
    margin-top: 10px;
    font-size: 12px;
    color: #6c757d;
}


/* =========================================================
   OWNER RESPONSIBILITY CONFIRMATION — ONE TIME
========================================================= */

.owner-responsibility-card {
    border: 1px solid #d6dee8;
}

.owner-responsibility-content {
    padding: 16px 18px 18px;
}

.owner-responsibility-statement {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 14px;
}

.owner-responsibility-statement strong {
    display: block;
    font-size: 13px;
    margin-bottom: 7px;
    color: #1e293b;
}

.owner-responsibility-statement p {
    margin: 0;
    font-size: 11px;
    line-height: 1.55;
    color: #475569;
}

.owner-responsibility-counts {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.owner-responsibility-counts span {
    padding: 5px 10px;
    border-radius: 999px;
    background: #eaf2ff;
    color: #26364d;
    font-size: 10px;
}

.owner-responsibility-counts b {
    font-weight: 800;
}

.owner-one-time-form {
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 14px;
    background: #fff;
}

.owner-confirm-main {
    font-size: 12px;
    margin-bottom: 12px;
}

.owner-confirm-main-btn {
    max-width: 360px;
}

.owner-confirmed-box {
    border: 1px solid #b7e4cc;
    background: #f0fdf4;
    border-radius: 10px;
    padding: 12px 14px;
}

.owner-confirmed-meta {
    margin-top: 7px;
    font-size: 10px;
    line-height: 1.5;
    color: #526174;
}

.owner-confirmed-document {
    display: inline-flex;
    margin-top: 8px;
}

.owner-pending-badge {
    display: inline-block;
    padding: 6px 9px;
    border-radius: 999px;
    background: #fff3cd;
    color: #7a5a00;
    font-size: 10px;
    font-weight: 700;
}

.owner-ca-pending-note {
    padding: 8px 10px;
    border-radius: 7px;
    background: #fff8e6;
    color: #7a5a00;
    font-size: 10px;
    line-height: 1.4;
}

/* =========================================================
   OWNER RESPONSIBILITY CONFIRMATION
========================================================= */

.owner-responsibility-form {
    width: 100%;
    padding: 10px;
    border: 1px solid #dfe5ec;
    border-radius: 9px;
    background: #fafbfd;
}

.owner-confirm-check {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    margin-bottom: 9px;
    font-size: 11px;
    line-height: 1.4;
    font-weight: 600;
    color: #26364d;
}

.owner-confirm-check input {
    margin-top: 2px;
    flex: 0 0 auto;
}

.owner-doc-label {
    display: block;
    margin-bottom: 4px;
    font-size: 10px;
    font-weight: 800;
    color: #526174;
    text-transform: uppercase;
}

.owner-doc-input {
    display: block;
    width: 100%;
    font-size: 10px;
    margin-bottom: 3px;
}

.owner-doc-help {
    display: block;
    color: #64748b;
    font-size: 9px;
    margin-bottom: 8px;
}

.owner-confirm-btn {
    width: 100%;
    border: 0;
    border-radius: 7px;
    padding: 8px 10px;
    background: #198754;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
}

.owner-confirm-btn:hover {
    background: #146c43;
}

.ca-owner-form {
    margin-bottom: 8px;
}

/* =========================================================
   OWNER CA DECISION
========================================================= */

.ca-owner-decision-cell {
    vertical-align: middle !important;
    min-width: 210px;
}

.ca-action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 7px;
}

.ca-action-buttons form {
    margin: 0;
}

.ca-approve-btn,
.ca-reject-btn {
    border: 0;
    border-radius: 7px;
    padding: 7px 11px;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}

.ca-approve-btn {
    background: #198754;
    color: #fff;
}

.ca-approve-btn:hover {
    background: #146c43;
}

.ca-reject-btn {
    background: #dc3545;
    color: #fff;
}

.ca-reject-btn:hover {
    background: #b02a37;
}

.ca-decision-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 9px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 800;
}

.ca-approved {
    background: #d9f4e5;
    color: #0c6b35;
}

.ca-rejected {
    background: #f9d9dc;
    color: #9b1c25;
}

.ca-decision-meta {
    margin-top: 6px;
    color: #64748b;
    font-size: 10px;
    line-height: 1.45;
}

.ca-rejection-reason {
    margin-top: 7px;
    padding: 7px 8px;
    border-left: 3px solid #dc3545;
    background: #fff5f5;
    color: #7f1d1d;
    font-size: 11px;
    line-height: 1.4;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

@media (max-width: 1100px) {

    .info-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 650px) {

    .info-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .page {
        padding: 14px 10px 30px;
    }
}

/* =========================================================
   NC CHECKPOINT TABLE — FIT FULL PAGE
========================================================= */

.nc-only-table th,
.nc-only-table td {
    box-sizing: border-box;
    vertical-align: top;
    overflow-wrap: anywhere;
    word-break: normal;
}

.nc-only-table th {
    white-space: nowrap;
}

.nc-only-table td:nth-child(1) {
    width: 9%;
}

.nc-only-table td:nth-child(2) {
    width: 7%;
    text-align: center;
}

.nc-only-table td:nth-child(3) {
    width: 10%;
}

.nc-only-table td:nth-child(4) {
    width: 8%;
    text-align: left;
}

.nc-only-table td:nth-child(5) {
    width: 18%;
    white-space: normal;
}

.nc-only-table td:nth-child(6) {
    width: 12%;
}

.nc-only-table td:nth-child(7) {
    width: 21%;
}

.nc-only-table .remark-cell,
.nc-only-table .remarks-cell {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: normal;
    line-height: 1.45;
}

.nc-only-table .owner-confirm-cell {
    white-space: normal;
    overflow-wrap: anywhere;
}

.nc-only-table .evidence-cell {
    white-space: normal;
}

@media (max-width: 900px) {
    .nc-only-table {
        font-size: 10px;
    }

    .nc-only-table th,
    .nc-only-table td {
        padding: 8px 6px;
    }

    .nc-only-table th:nth-child(3),
    .nc-only-table td:nth-child(3) {
        width: 23%;
    }

    .nc-only-table th:nth-child(5),
    .nc-only-table td:nth-child(5) {
        width: 20%;
    }

    .nc-only-table th:nth-child(7),
    .nc-only-table td:nth-child(7) {
        width: 23%;
    }
}


/* =========================================================
   OWNER NC / CA PDF DOWNLOAD BUTTONS
========================================================= */
.owner-pdf-titlebar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}

.owner-pdf-btn {
    white-space: nowrap;
    border-radius: 8px;
    font-weight: 600;
}

@media (max-width: 575.98px) {
    .owner-pdf-titlebar {
        align-items: flex-start;
    }

    .owner-pdf-btn {
        width: 100%;
    }
}

@media print {
    .no-pdf,
    form,
    button,
    input,
    textarea,
    select,
    .modal,
    .navbar,
    .sidebar,
    .page-header-actions {
        display: none !important;
    }
}
</style>


<div class="content">

<div class="page">


    <!-- =====================================================
         SESSION MESSAGES
    ====================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle me-1"></i>

            <?= h($_SESSION['success']) ?>

            <?php unset($_SESSION['success']); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-circle me-1"></i>

            <?= h($_SESSION['error']) ?>

            <?php unset($_SESSION['error']); ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         INSPECTION INFORMATION
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-file-earmark-check"></i>

            Inspection Information

        </div>


        <div class="info-grid">

            <div class="info-item">
                <div class="info-label">Checklist</div>
                <div class="info-value">
                    <?= h($checklistCode) ?>
                    /
                    <?= h($checklistRevision) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Module</div>
                <div class="info-value">
                    <?= h($module) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Service</div>
                <div class="info-value">
                    <?= h($displayServiceName) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Asset / Lift</div>
                <div class="info-value">
                    <?= h($displayAsset) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value">
                    <?= h(
                        $job['location']
                        ?:
                        '—'
                    ) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Inspection Date</div>
                <div class="info-value">
                    <?= h($inspectionDate) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">PSGA No.</div>
                <div class="info-value">
                    <?= h(
                        $job['psga_no']
                        ?:
                        '—'
                    ) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">SAF No.</div>
                <div class="info-value">
                    <?= h(
                        $job['saf_no']
                        ?:
                        '—'
                    ) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Inspector</div>
                <div class="info-value">
                    <?= h(
                        $job['inspector_name']
                        ?:
                        '—'
                    ) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Inspection Status</div>
                <div class="info-value">
                    <span class="status <?= h($statusClass) ?>">
                        <?= h($statusText) ?>
                    </span>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">Final Status</div>
                <div class="info-value">
                    <?= h($finalStatus) ?>
                </div>
            </div>


            <div class="info-item">
                <div class="info-label">
                    Saved At (IST)
                </div>

                <div class="info-value">

                    <?= formatDbUtcToIst(
                        $job['updated_at']
                    ) ?>

                </div>
            </div>

        </div>

    </div>


    <!-- =====================================================
         OWNER NC SUMMARY
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-bar-chart"></i>

            <?= h($isSci ? 'SCI Inspection Summary' : ($isSat ? 'SAT Inspection Summary' : 'PAT Inspection Summary')) ?>

        </div>


        <div class="summary-grid owner-summary-grid">

            <div class="summary summary-nc">

                <span>
                    NC Checkpoints
                </span>

                <strong>
                    <?= (int)$ownerNcTotal ?>
                </strong>

                <small>
                    Action Required
                </small>

            </div>


            <div class="summary summary-ca">

                <span>
                    CA Checkpoints
                </span>

                <strong>
                    <?= (int)$ownerCaTotal ?>
                </strong>

                <small>
                    Conditional Acceptance
                </small>

            </div>


            <div class="summary summary-photo">

                <span>
                    Photos
                </span>

                <strong>
                    <?= (int)$photoCount ?>
                </strong>

                <small>
                    Uploaded Evidence
                </small>

            </div>


            <div class="summary summary-video">

                <span>
                    Videos
                </span>

                <strong>
                    <?= (int)$videoCount ?>
                </strong>

                <small>
                    Uploaded Evidence
                </small>

            </div>

        </div>

    </div>


    <!-- =====================================================
         OWNER RESPONSIBILITY CONFIRMATION — ONE TIME
    ====================================================== -->

    <?php if ($ownerNcTotal > 0 || $ownerCaTotal > 0): ?>

        <div class="card-box owner-responsibility-card">

            <div class="card-title">
                <i class="bi bi-shield-check"></i>
                Owner Responsibility Confirmation
            </div>

            <div class="owner-responsibility-content">

                <div class="owner-responsibility-statement">

                    <strong>
                        NC &amp; CA Checkpoints — One-Time Owner Confirmation
                    </strong>

                    <p>
                        I confirm that the NC and CA checkpoints recorded in this
                        inspection are under the Owner's responsibility. I undertake
                        to rectify/correct the identified issues and submit the
                        required supporting compliance/rectification document.
                    </p>

                    <div class="owner-responsibility-counts">
                        <span>
                            <b><?= (int)$ownerNcTotal ?></b> NC Checkpoints
                        </span>
                        <span>
                            <b><?= (int)$ownerCaTotal ?></b> CA Checkpoints
                        </span>
                    </div>

                </div>

                <?php if ($ownerResponsibilityConfirmed): ?>

                    <div class="owner-confirmed-box">

                        <span class="ca-decision-badge ca-approved">
                            <i class="bi bi-check-circle"></i>
                            Owner Responsibility Confirmed
                        </span>

                        <div class="owner-confirmed-meta">
                            Confirmation covers all NC &amp; CA checkpoints.
                            <?php if ($ownerResponsibilityConfirmedAt !== ''): ?>
                                <br>
                                Confirmed:
                                <?= h(formatIstDate($ownerResponsibilityConfirmedAt)) ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($ownerResponsibilityDocument !== ''): ?>

                            <a
                                href="<?= h(evidenceUrl($ownerResponsibilityDocument)) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="evidence-link owner-confirmed-document"
                            >
                                <i class="bi bi-file-earmark-check"></i>
                                <?= h($ownerResponsibilityDocumentName ?: 'View Submitted Document') ?>
                            </a>

                        <?php endif; ?>

                    </div>

                <?php else: ?>

                    <form
                        method="POST"
                        action=""
                        enctype="multipart/form-data"
                        class="owner-one-time-form"
                    >

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="job_id"
                            value="<?= (int)$jobId ?>"
                        >

                        <input
                            type="hidden"
                            name="owner_action"
                            value="owner_responsibility_confirm"
                        >

                        <label class="owner-confirm-check owner-confirm-main">
                            <input
                                type="checkbox"
                                name="owner_responsibility_confirm"
                                value="1"
                                required
                            >

                            <span>
                                I confirm that all NC and CA checkpoints above are
                                the Owner's responsibility, and I undertake to
                                rectify/correct them and submit the required
                                supporting document.
                            </span>
                        </label>

                        <label class="owner-doc-label">
                            Supporting Compliance / Rectification Document
                        </label>

                        <input
                            type="file"
                            name="owner_compliance_document"
                            class="owner-doc-input"
                            accept=".pdf,.jpg,.jpeg,.png"
                            required
                        >

                        <small class="owner-doc-help">
                            One document for this inspection · PDF/JPG/JPEG/PNG · Maximum 7 MB
                        </small>

                        <button
                            type="submit"
                            class="owner-confirm-btn owner-confirm-main-btn"
                            onclick="return confirm('Confirm Owner responsibility for all NC and CA checkpoints and submit the supporting document?');"
                        >
                            <i class="bi bi-check2-circle"></i>
                            Confirm Owner Responsibility &amp; Submit
                        </button>

                    </form>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         OWNER NC-ONLY CHECKLIST
    ====================================================== -->

    <div class="card-box" id="ownerNcPdfSection">

        <div class="card-title owner-pdf-titlebar">

            <span>
                <i class="bi bi-exclamation-triangle"></i>

                <?= h($isSci ? 'NC Checkpoints — SCI' : ($isSat ? 'NC Checkpoints — SAT' : 'NC Checkpoints — PAT')) ?>
            </span>

            <?php if ($ownerNcTotal > 0): ?>
                <button
                    type="button"
                    class="btn btn-sm btn-outline-danger owner-pdf-btn no-pdf"
                    onclick="downloadOwnerSectionPdf('ownerNcPdfSection', 'NLETA_NC_Checklist_<?= (int)$jobId ?>')"
                >
                    <i class="bi bi-file-earmark-pdf"></i>
                    Download PDF
                </button>
            <?php endif; ?>

        </div>



        <?php if ($ownerNcTotal === 0): ?>

            <div class="no-nc-box">

                <i class="bi bi-check-circle"></i>

                <strong>
                    No NC checkpoints
                </strong>

                <span>
                    No non-compliance items were recorded
                    in this inspection.
                </span>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table
                    class="checklist-table nc-only-table"
                >
                        <colgroup>
                            <col style="width:8%;">
                            <col style="width:7%;">
                            <col style="width:8%;">
                            <col style="width:21%;">
                            <col style="width:9%;">
                            <col style="width:15%;">
                            <col style="width:15%;">
                            <col style="width:11%;">
                            <col style="width:14%;">
                        </colgroup>

                    <thead>

                        <tr>

                            <th style="width:110px;">
                                Checkpoint
                            </th>

                            <th style="width:90px;">
                                Priority
                            </th>

                            <th style="width:90px;">
                                Section
                            </th>

                            <th style="min-width:260px;">
                                Checkpoint Name
                            </th>

                            <th style="width:110px;">
                                Actual Reading
                            </th>

                            <th style="width:110px;">
                                Result
                            </th>

                            <th>
                                Remark
                            </th>

                            <th style="width:300px;">
                                Evidence
                            </th>

                            <th style="width:250px;">
                                Owner Confirmation
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                    <?php foreach (
                        $ownerNcItems
                        as $ref => $item
                    ): ?>

                        <?php

                        $remark =
                            (string)(
                                $item['remark']
                                ?? ''
                            );

                        if ((string)$ref === 'ROPE-SUP-01') {
                            $ropeDetails = [];

                            $ropeConditionText = trim((string)(
                                $item['physical_condition'] ?? ''
                            ));
                            $ropeAgeText = $item['age_years'] ?? null;
                            $ropeReplacementText = trim((string)(
                                $item['replacement_date'] ?? ''
                            ));

                            if ($ropeConditionText !== '') {
                                $ropeDetails[] =
                                    'Physical condition: ' .
                                    $ropeConditionText;
                            }

                            if ($ropeAgeText !== null && $ropeAgeText !== '') {
                                $ropeDetails[] =
                                    'Age: ' . $ropeAgeText . ' year(s)';
                            }

                            if ($ropeReplacementText !== '') {
                                $ropeDetails[] =
                                    'Replacement date: ' .
                                    $ropeReplacementText;
                            }

                            if (!empty($ropeDetails)) {
                                $remark =
                                    implode(' | ', $ropeDetails) .
                                    ($remark !== ''
                                        ? ' | ' . $remark
                                        : '');
                            }
                        }

                        $photo = trim((string)(
                            $item['photo']
                            ?? $item['photo_path']
                            ?? ''
                        ));

                        $video = trim((string)(
                            $item['video']
                            ?? $item['video_path']
                            ?? ''
                        ));

                        $document = trim((string)(
                            $item['document']
                            ?? $item['document_path']
                            ?? ''
                        ));

                        if (
                            isset($item['evidence'])
                            && is_array($item['evidence'])
                        ) {
                            if (
                                $photo === ''
                                && !empty($item['evidence']['photo'])
                            ) {
                                $photo = (string)$item['evidence']['photo'];
                            }

                            if (
                                $video === ''
                                && !empty($item['evidence']['video'])
                            ) {
                                $video = (string)$item['evidence']['video'];
                            }

                            if (
                                $document === ''
                                && !empty($item['evidence']['document'])
                            ) {
                                $document = (string)$item['evidence']['document'];
                            }
                        }

                        /*
                         * Database fallback for this checkpoint.
                         * Photo and Video are merged independently.
                         */
                        if (isset($databaseEvidence[$ref])) {
                            if (
                                $photo === ''
                                && !empty($databaseEvidence[$ref]['photo'])
                            ) {
                                $photo = (string)$databaseEvidence[$ref]['photo'];
                            }

                            if (
                                $video === ''
                                && !empty($databaseEvidence[$ref]['video'])
                            ) {
                                $video = (string)$databaseEvidence[$ref]['video'];
                            }

                            if (
                                $document === ''
                                && !empty($databaseEvidence[$ref]['document'])
                            ) {
                                $document = (string)$databaseEvidence[$ref]['document'];
                            }
                        }

                        ?>

                        <tr>

                            <td>

                                <span class="checkpoint-id">
                                    <?= h($ref) ?>
                                </span>

                            </td>

                            <?php
                            $rowMeta =
                                $displayMeta[(string)$ref]
                                ?? checkpointDisplayMeta(
                                    (string)$ref,
                                    is_array($item) ? $item : [],
                                    $checkpointMeta,
                                    $patCheckpointFallbackNames
                                );

                            if ((string)$ref === 'ROPE-SUP-01') {
                                $rowMeta = [
                                    'priority' => 'P1',
                                    'section' => 'L',
                                    'name' => (string)(
                                        $item['checkpoint_name']
                                        ?? 'Rope physical condition / replacement age assessment'
                                    )
                                ];
                            }
                            ?>

                            <td>
                                <span class="priority-badge">
                                    <?= h($rowMeta['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($rowMeta['section']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($rowMeta['name']) ?>
                                </div>
                            </td>

                            <td>
                                <?php
                                $fixedReading = trim((string)(
                                    $item['actual_reading']
                                    ?? $item['reading']
                                    ?? ''
                                ));
                                ?>
                                <?php if ($fixedReading !== ''): ?>
                                    <?= nl2br(h($fixedReading)) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>


                            <td>

                                <span
                                    class="result-badge result-nc"
                                >
                                    NC
                                </span>

                            </td>


                            <td class="remark-cell">

                                <?php if (
                                    trim($remark)
                                    !==
                                    ''
                                ): ?>

                                    <?= nl2br(
                                        h($remark)
                                    ) ?>

                                <?php else: ?>

                                    <span class="text-muted">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="evidence-links">

                                    <?php if (
                                        $photo
                                        !==
                                        ''
                                    ): ?>

                                        <a
                                            href="<?= h(
                                                evidenceUrl(
                                                    $photo
                                                )
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >

                                            <i class="bi bi-image"></i>

                                            Photo

                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $video
                                        !==
                                        ''
                                    ): ?>

                                        <a
                                            href="<?= h(
                                                evidenceUrl(
                                                    $video
                                                )
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >

                                            <i class="bi bi-camera-video"></i>

                                            Video

                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $document
                                        !==
                                        ''
                                    ): ?>

                                        <a
                                            href="<?= h(
                                                evidenceUrl(
                                                    $document
                                                )
                                            ) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >

                                            <i class="bi bi-file-earmark"></i>

                                            Document

                                        </a>

                                    <?php endif; ?>


                                    <?php if (
                                        $photo === ''
                                        &&
                                        $video === ''
                                        &&
                                        $document === ''
                                    ): ?>

                                        <span class="empty-evidence">

                                            No evidence uploaded

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <td>
                                <?php if ($ownerResponsibilityConfirmed): ?>
                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Confirmation Covered
                                    </span>
                                <?php else: ?>
                                    <span class="owner-pending-badge">
                                        Pending One-Time Confirmation
                                    </span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <?php foreach ($ownerNcMatrixItems as $matrixRef => $matrixItem): ?>

                        <?php
                        $matrixName = trim((string)(
                            $matrixItem['checkpoint_name']
                            ?? $matrixItem['matrix_key']
                            ?? $matrixRef
                        ));

                        $matrixPriority = trim((string)(
                            $matrixItem['priority']
                            ?? ''
                        ));

                        $matrixSection = trim((string)(
                            $matrixItem['section']
                            ?? ''
                        ));

                        $matrixKey = trim((string)(
                            $matrixItem['matrix_key']
                            ?? ''
                        ));

                        $matrixCondition = trim((string)(
                            $matrixItem['load_condition']
                            ?? ''
                        ));

                        $matrixReading = trim((string)(
                            $matrixItem['actual_reading']
                            ?? ''
                        ));

                        $matrixRemark = trim((string)(
                            $matrixItem['remark']
                            ?? ''
                        ));

                        $matrixTechnical = trim((string)(
                            $matrixItem['technical_justification']
                            ?? ''
                        ));

                        $matrixPhoto = trim((string)(
                            $matrixItem['photo']
                            ?? $matrixItem['photo_path']
                            ?? ''
                        ));

                        $matrixVideo = trim((string)(
                            $matrixItem['video']
                            ?? $matrixItem['video_path']
                            ?? ''
                        ));

                        $matrixDocument = trim((string)(
                            $matrixItem['document']
                            ?? $matrixItem['document_path']
                            ?? ''
                        ));

                        if (
                            isset($matrixItem['evidence'])
                            && is_array($matrixItem['evidence'])
                        ) {
                            if (
                                $matrixPhoto === ''
                                && !empty($matrixItem['evidence']['photo'])
                            ) {
                                $matrixPhoto = (string)$matrixItem['evidence']['photo'];
                            }

                            if (
                                $matrixVideo === ''
                                && !empty($matrixItem['evidence']['video'])
                            ) {
                                $matrixVideo = (string)$matrixItem['evidence']['video'];
                            }

                            if (
                                $matrixDocument === ''
                                && !empty($matrixItem['evidence']['document'])
                            ) {
                                $matrixDocument = (string)$matrixItem['evidence']['document'];
                            }
                        }

                        if (isset($databaseEvidence[$matrixRef])) {
                            $ev = $databaseEvidence[$matrixRef];

                            if ($matrixPhoto === '' && !empty($ev['photo'])) {
                                $matrixPhoto = (string)$ev['photo'];
                            }

                            if ($matrixVideo === '' && !empty($ev['video'])) {
                                $matrixVideo = (string)$ev['video'];
                            }

                            if ($matrixDocument === '' && !empty($ev['document'])) {
                                $matrixDocument = (string)$ev['document'];
                            }
                        }

                        $matrixRemarkDisplay = $matrixRemark;

                        if ($matrixTechnical !== '') {
                            $matrixRemarkDisplay .=
                                ($matrixRemarkDisplay !== '' ? "\n" : '')
                                . 'Technical / CA Justification: '
                                . $matrixTechnical;
                        }
                        ?>

                        <tr>

                            <td>
                                <span class="checkpoint-id">
                                    <?= h($matrixRef) ?>
                                </span>
                                <?php if ($matrixCondition !== ''): ?>
                                    <div class="small text-muted mt-1">
                                        Load: <?= h($matrixCondition) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="priority-badge">
                                    <?= h($matrixPriority !== '' ? $matrixPriority : '—') ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($matrixSection !== '' ? $matrixSection : '—') ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($matrixName) ?>
                                </div>
                                <?php if ($matrixKey !== ''): ?>
                                    <div class="small text-muted mt-1">
                                        Parameter: <?= h($matrixKey) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($matrixReading !== ''): ?>
                                    <strong><?= h($matrixReading) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="result-badge result-nc">
                                    NC
                                </span>
                            </td>

                            <td class="remark-cell">
                                <?php if ($matrixRemarkDisplay !== ''): ?>
                                    <?= nl2br(h($matrixRemarkDisplay)) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">

                                    <?php if ($matrixPhoto !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixPhoto)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-image"></i>
                                            Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($matrixVideo !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixVideo)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-camera-video"></i>
                                            Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($matrixDocument !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixDocument)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-file-earmark"></i>
                                            Document
                                        </a>
                                    <?php endif; ?>

                                    <?php if (
                                        $matrixPhoto === ''
                                        && $matrixVideo === ''
                                        && $matrixDocument === ''
                                    ): ?>
                                        <span class="empty-evidence">
                                            No evidence uploaded
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td>
                                <?php if ($ownerResponsibilityConfirmed): ?>
                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Confirmation Covered
                                    </span>
                                <?php else: ?>
                                    <span class="owner-pending-badge">
                                        Pending One-Time Confirmation
                                    </span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>


                    <?php foreach ($floorNcItems as $floorItem): ?>

                        <tr>

                            <td>
                                <span class="checkpoint-id">
                                    <?= h($floorItem['checkpoint']) ?>
                                </span>
                                <div class="small text-muted mt-1">
                                    Floor: <?= h($floorItem['floor']) ?>
                                </div>
                            </td>

                            <?php
                            $floorMetaKey =
                                'FLOOR|'
                                . $floorItem['floor']
                                . '|'
                                . $floorItem['checkpoint'];

                            $floorMeta =
                                $displayMeta[$floorMetaKey]
                                ?? checkpointDisplayMeta(
                                    (string)$floorItem['checkpoint'],
                                    [],
                                    $checkpointMeta,
                                    []
                                );
                            ?>

                            <td>
                                <span class="priority-badge">
                                    <?= h($floorMeta['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($floorMeta['section']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($floorMeta['name']) ?>
                                </div>
                            </td>

                            <td>
                                <span class="text-muted">—</span>
                            </td>

                            <td>
                                <span class="result-badge result-nc">
                                    NC
                                </span>
                            </td>

                            <td class="remark-cell">
                                <?php if (trim($floorItem['remark']) !== ''): ?>
                                    <?= nl2br(h($floorItem['remark'])) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">

                                    <?php
                                    $floorPhoto = trim((string)(
                                        $floorItem['photo']
                                        ?? $floorItem['photo_path']
                                        ?? ''
                                    ));

                                    $floorVideo = trim((string)(
                                        $floorItem['video']
                                        ?? $floorItem['video_path']
                                        ?? ''
                                    ));

                                    if (
                                        isset($floorItem['evidence'])
                                        && is_array($floorItem['evidence'])
                                    ) {
                                        if (
                                            $floorPhoto === ''
                                            && !empty($floorItem['evidence']['photo'])
                                        ) {
                                            $floorPhoto = (string)$floorItem['evidence']['photo'];
                                        }

                                        if (
                                            $floorVideo === ''
                                            && !empty($floorItem['evidence']['video'])
                                        ) {
                                            $floorVideo = (string)$floorItem['evidence']['video'];
                                        }
                                    }
                                    ?>

                                    <?php if ($floorPhoto !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($floorPhoto)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-image"></i>
                                            Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($floorVideo !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($floorVideo)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-camera-video"></i>
                                            Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($floorPhoto === '' && $floorVideo === ''): ?>
                                        <span class="empty-evidence">
                                            No evidence uploaded
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td>
                                <?php if ($ownerResponsibilityConfirmed): ?>
                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Confirmation Covered
                                    </span>
                                <?php else: ?>
                                    <span class="owner-pending-badge">
                                        Pending One-Time Confirmation
                                    </span>
                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>



    <!-- =====================================================
         OWNER CA / CONDITIONAL ACCEPTANCE
    ====================================================== -->

    <?php if ($ownerCaTotal > 0): ?>

        <div class="card-box" id="ownerCaPdfSection">

            <div class="card-title owner-pdf-titlebar">

                <span>
                    <i class="bi bi-shield-check"></i>

                    Conditional Acceptance (CA)
                </span>

                <button
                    type="button"
                    class="btn btn-sm btn-outline-primary owner-pdf-btn no-pdf"
                    onclick="downloadOwnerSectionPdf('ownerCaPdfSection', 'NLETA_CA_Checklist_<?= (int)$jobId ?>')"
                >
                    <i class="bi bi-file-earmark-pdf"></i>
                    Download PDF
                </button>

            </div>

            <div class="table-wrap">

                <table class="checklist-table ca-table">
                    <colgroup>
                        <col class="ca-col-checkpoint">
                        <col class="ca-col-priority">
                        <col class="ca-col-section">
                        <col class="ca-col-name">
                        <col class="ca-col-reading">
                        <col class="ca-col-result">
                        <col class="ca-col-remarks">
                        <col class="ca-col-evidence">
                        <col class="ca-col-owner">
                    </colgroup>

                    <thead>
                        <tr>
                            <th>Checkpoint</th>
                            <th>Priority</th>
                            <th>Section</th>
                            <th>Checkpoint Name</th>
                            <th>Actual Reading</th>
                            <th>Result</th>
                            <th>Remarks</th>
                            <th>Evidence</th>
                            <th>Owner Decision</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($ownerCaItems as $ref => $item): ?>

                        <?php
                        $caRemark = trim((string)($item['remark'] ?? ''));

                        if ((string)$ref === 'ROPE-SUP-01') {
                            $ropeDetails = [];

                            $ropeConditionText = trim((string)(
                                $item['physical_condition'] ?? ''
                            ));
                            $ropeAgeText = $item['age_years'] ?? null;
                            $ropeReplacementText = trim((string)(
                                $item['replacement_date'] ?? ''
                            ));

                            if ($ropeConditionText !== '') {
                                $ropeDetails[] =
                                    'Physical condition: ' .
                                    $ropeConditionText;
                            }

                            if ($ropeAgeText !== null && $ropeAgeText !== '') {
                                $ropeDetails[] =
                                    'Age: ' . $ropeAgeText . ' year(s)';
                            }

                            if ($ropeReplacementText !== '') {
                                $ropeDetails[] =
                                    'Replacement date: ' .
                                    $ropeReplacementText;
                            }

                            if (!empty($ropeDetails)) {
                                $caRemark =
                                    implode(' | ', $ropeDetails) .
                                    ($caRemark !== ''
                                        ? ' | ' . $caRemark
                                        : '');
                            }
                        }

                        $caJustification = trim((string)(
                            $item['ca_remark']
                            ?? $item['technical_justification']
                            ?? ''
                        ));

                        $caPhoto = trim((string)(
                            $item['photo']
                            ?? $item['photo_path']
                            ?? ''
                        ));
                        $caVideo = trim((string)(
                            $item['video']
                            ?? $item['video_path']
                            ?? ''
                        ));
                        $caDocument = trim((string)(
                            $item['document']
                            ?? $item['document_path']
                            ?? ''
                        ));

                        if (
                            isset($item['evidence'])
                            && is_array($item['evidence'])
                        ) {
                            if (
                                $caPhoto === ''
                                && !empty($item['evidence']['photo'])
                            ) {
                                $caPhoto = (string)$item['evidence']['photo'];
                            }

                            if (
                                $caVideo === ''
                                && !empty($item['evidence']['video'])
                            ) {
                                $caVideo = (string)$item['evidence']['video'];
                            }

                            if (
                                $caDocument === ''
                                && !empty($item['evidence']['document'])
                            ) {
                                $caDocument = (string)$item['evidence']['document'];
                            }
                        }
                        ?>

                        <tr>
                            <td>
                                <span class="checkpoint-id">
                                    <?= h($ref) ?>
                                </span>
                            </td>

                            <?php
                            $caRowMeta =
                                $displayMeta[(string)$ref]
                                ?? checkpointDisplayMeta(
                                    (string)$ref,
                                    is_array($item) ? $item : [],
                                    $checkpointMeta,
                                    $patCheckpointFallbackNames
                                );

                            if ((string)$ref === 'ROPE-SUP-01') {
                                $caRowMeta = [
                                    'priority' => 'P1',
                                    'section' => 'L',
                                    'name' => (string)(
                                        $item['checkpoint_name']
                                        ?? 'Rope physical condition / replacement age assessment'
                                    )
                                ];
                            }
                            ?>

                            <td>
                                <span class="priority-badge">
                                    <?= h($caRowMeta['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($caRowMeta['section']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($caRowMeta['name']) ?>
                                </div>
                            </td>

                            <td>
                                <?php
                                $caFixedReading = trim((string)(
                                    $item['actual_reading']
                                    ?? $item['reading']
                                    ?? ''
                                ));
                                ?>
                                <?php if ($caFixedReading !== ''): ?>
                                    <?= nl2br(h($caFixedReading)) ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="result-badge result-ca">
                                    CA
                                </span>
                            </td>

                            <td class="remark-cell">
                                <?php if ($caRemark !== ''): ?>
                                    <?= nl2br(h($caRemark)) ?>
                                <?php endif; ?>

                                <?php if ($caJustification !== ''): ?>
                                    <?php if ($caRemark !== ''): ?><br><?php endif; ?>
                                    <?= nl2br(h($caJustification)) ?>
                                <?php endif; ?>

                                <?php if ($caRemark === '' && $caJustification === ''): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">
                                    <?php if ($caPhoto !== ''): ?>
                                        <a href="<?= h(evidenceUrl($caPhoto)) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="evidence-link">
                                            <i class="bi bi-image"></i> Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($caVideo !== ''): ?>
                                        <a href="<?= h(evidenceUrl($caVideo)) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="evidence-link">
                                            <i class="bi bi-camera-video"></i> Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($caDocument !== ''): ?>
                                        <a href="<?= h(evidenceUrl($caDocument)) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="evidence-link">
                                            <i class="bi bi-file-earmark"></i> Document
                                        </a>
                                    <?php endif; ?>

                                    <?php if (
                                        $caPhoto === ''
                                        && $caVideo === ''
                                        && $caDocument === ''
                                    ): ?>
                                        <span class="empty-evidence">
                                            No evidence uploaded
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="ca-owner-decision-cell">
                                <?php
                                $ownerCaDecision =
                                    (isset($item['owner_ca_decision']) && is_array($item['owner_ca_decision']))
                                        ? $item['owner_ca_decision']
                                        : [];

                                $ownerCaDecisionStatus =
                                    strtolower(trim((string)($ownerCaDecision['status'] ?? '')));
                                ?>

                                <?php if ($ownerCaDecisionStatus === 'approved'): ?>

                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Approved
                                    </span>

                                    <div class="ca-decision-meta">
                                        Permission recorded
                                        <?php if (!empty($ownerCaDecision['decided_at_ist'])): ?>
                                            <br>
                                            <?= formatIstDate($ownerCaDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($ownerCaDecisionStatus === 'rejected'): ?>

                                    <span class="ca-decision-badge ca-rejected">
                                        <i class="bi bi-x-circle"></i>
                                        Owner Rejected
                                    </span>

                                    <?php if (!empty($ownerCaDecision['reason'])): ?>
                                        <div class="ca-rejection-reason">
                                            <?= nl2br(h($ownerCaDecision['reason'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ca-decision-meta">
                                        <?php if (!empty($ownerCaDecision['decided_at_ist'])): ?>
                                            <?= formatIstDate($ownerCaDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>

                                    <form method="POST"
                                          action=""
                                          class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_approve">
                                        <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                        <input type="hidden" name="ca_ref" value="<?= h($ref) ?>">
                                        <input type="hidden" name="ca_floor" value="">

                                        <button type="submit"
                                                class="ca-approve-btn"
                                                onclick="return confirm('Accept this Conditional Acceptance?');">
                                            <i class="bi bi-check-circle"></i>
                                            Accept
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action=""
                                          class="d-inline mt-2"
                                          onsubmit="return prepareCaReject(this);">

                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_reject">
                                        <input type="hidden" name="ca_ref" value="<?= h($ref) ?>">
                                        <input type="hidden" name="ca_floor" value="">
                                        <input type="hidden" name="ca_reject_reason" value="">

                                        <button type="submit"
                                                class="ca-reject-btn">
                                            <i class="bi bi-x-circle"></i>
                                            Reject
                                        </button>
                                    </form>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    <?php foreach ($ownerCaMatrixItems as $matrixRef => $matrixItem): ?>

                        <?php
                        $matrixName = trim((string)(
                            $matrixItem['checkpoint_name']
                            ?? $matrixItem['matrix_key']
                            ?? $matrixRef
                        ));

                        $matrixPriority = trim((string)(
                            $matrixItem['priority']
                            ?? ''
                        ));

                        $matrixSection = trim((string)(
                            $matrixItem['section']
                            ?? ''
                        ));

                        $matrixKey = trim((string)(
                            $matrixItem['matrix_key']
                            ?? ''
                        ));

                        $matrixCondition = trim((string)(
                            $matrixItem['load_condition']
                            ?? ''
                        ));

                        $matrixReading = trim((string)(
                            $matrixItem['actual_reading']
                            ?? ''
                        ));

                        $matrixRemark = trim((string)(
                            $matrixItem['remark']
                            ?? ''
                        ));

                        $matrixJustification = trim((string)(
                            $matrixItem['technical_justification']
                            ?? ''
                        ));

                        $matrixPhoto = trim((string)(
                            $matrixItem['photo']
                            ?? $matrixItem['photo_path']
                            ?? ''
                        ));

                        $matrixVideo = trim((string)(
                            $matrixItem['video']
                            ?? $matrixItem['video_path']
                            ?? ''
                        ));

                        $matrixDocument = trim((string)(
                            $matrixItem['document']
                            ?? $matrixItem['document_path']
                            ?? ''
                        ));

                        if (
                            isset($matrixItem['evidence'])
                            && is_array($matrixItem['evidence'])
                        ) {
                            if ($matrixPhoto === '' && !empty($matrixItem['evidence']['photo'])) {
                                $matrixPhoto = (string)$matrixItem['evidence']['photo'];
                            }
                            if ($matrixVideo === '' && !empty($matrixItem['evidence']['video'])) {
                                $matrixVideo = (string)$matrixItem['evidence']['video'];
                            }
                            if ($matrixDocument === '' && !empty($matrixItem['evidence']['document'])) {
                                $matrixDocument = (string)$matrixItem['evidence']['document'];
                            }
                        }

                        if (isset($databaseEvidence[$matrixRef])) {
                            $ev = $databaseEvidence[$matrixRef];

                            if ($matrixPhoto === '' && !empty($ev['photo'])) {
                                $matrixPhoto = (string)$ev['photo'];
                            }
                            if ($matrixVideo === '' && !empty($ev['video'])) {
                                $matrixVideo = (string)$ev['video'];
                            }
                            if ($matrixDocument === '' && !empty($ev['document'])) {
                                $matrixDocument = (string)$ev['document'];
                            }
                        }

                        $matrixDecision =
                            (isset($matrixItem['owner_ca_decision']) && is_array($matrixItem['owner_ca_decision']))
                                ? $matrixItem['owner_ca_decision']
                                : [];

                        $matrixDecisionStatus =
                            strtolower(trim((string)($matrixDecision['status'] ?? '')));
                        ?>

                        <tr>

                            <td>
                                <span class="checkpoint-id">
                                    <?= h($matrixRef) ?>
                                </span>
                                <?php if ($matrixCondition !== ''): ?>
                                    <div class="small text-muted mt-1">
                                        Load: <?= h($matrixCondition) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="priority-badge">
                                    <?= h($matrixPriority !== '' ? $matrixPriority : '—') ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($matrixSection !== '' ? $matrixSection : '—') ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($matrixName) ?>
                                </div>
                                <?php if ($matrixKey !== ''): ?>
                                    <div class="small text-muted mt-1">
                                        Parameter: <?= h($matrixKey) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($matrixReading !== ''): ?>
                                    <strong><?= h($matrixReading) ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="result-badge result-ca">
                                    CA
                                </span>
                            </td>

                            <td class="remark-cell">
                                <?php if ($matrixRemark !== ''): ?>
                                    <?= nl2br(h($matrixRemark)) ?>
                                <?php endif; ?>

                                <?php if ($matrixJustification !== ''): ?>
                                    <?php if ($matrixRemark !== ''): ?><br><?php endif; ?>
                                    <?= nl2br(h($matrixJustification)) ?>
                                <?php endif; ?>

                                <?php if ($matrixRemark === '' && $matrixJustification === ''): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">

                                    <?php if ($matrixPhoto !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixPhoto)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-image"></i>
                                            Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($matrixVideo !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixVideo)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-camera-video"></i>
                                            Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($matrixDocument !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($matrixDocument)) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-file-earmark"></i>
                                            Document
                                        </a>
                                    <?php endif; ?>

                                    <?php if (
                                        $matrixPhoto === ''
                                        && $matrixVideo === ''
                                        && $matrixDocument === ''
                                    ): ?>
                                        <span class="empty-evidence">
                                            No evidence uploaded
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </td>

                            <td class="ca-owner-decision-cell">

                                <?php if ($matrixDecisionStatus === 'approved'): ?>

                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Approved
                                    </span>

                                    <div class="ca-decision-meta">
                                        Permission recorded
                                        <?php if (!empty($matrixDecision['decided_at_ist'])): ?>
                                            <br>
                                            <?= formatIstDate($matrixDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($matrixDecisionStatus === 'rejected'): ?>

                                    <span class="ca-decision-badge ca-rejected">
                                        <i class="bi bi-x-circle"></i>
                                        Owner Rejected
                                    </span>

                                    <?php if (!empty($matrixDecision['reason'])): ?>
                                        <div class="ca-rejection-reason">
                                            <?= nl2br(h($matrixDecision['reason'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ca-decision-meta">
                                        <?php if (!empty($matrixDecision['decided_at_ist'])): ?>
                                            <?= formatIstDate($matrixDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>

                                <?php else: ?>

                                    <form method="POST"
                                          action=""
                                          class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_approve">
                                        <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                        <input type="hidden" name="ca_ref" value="<?= h($matrixRef) ?>">
                                        <input type="hidden" name="ca_floor" value="">
                                        <input type="hidden" name="ca_matrix" value="1">

                                        <button type="submit"
                                                class="ca-approve-btn"
                                                onclick="return confirm('Accept this Conditional Acceptance?');">
                                            <i class="bi bi-check-circle"></i>
                                            Accept
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action=""
                                          class="d-inline mt-2"
                                          onsubmit="return prepareCaReject(this);">

                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_reject">
                                        <input type="hidden" name="ca_ref" value="<?= h($matrixRef) ?>">
                                        <input type="hidden" name="ca_floor" value="">
                                        <input type="hidden" name="ca_matrix" value="1">
                                        <input type="hidden" name="ca_reject_reason" value="">

                                        <button type="submit"
                                                class="ca-reject-btn">
                                            <i class="bi bi-x-circle"></i>
                                            Reject
                                        </button>
                                    </form>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                    <?php foreach ($floorCaItems as $floorItem): ?>

                        <tr>
                            <td>
                                <span class="checkpoint-id">
                                    <?= h($floorItem['checkpoint']) ?>
                                </span>
                                <div class="small text-muted mt-1">
                                    Floor: <?= h($floorItem['floor']) ?>
                                </div>
                            </td>

                            <?php
                            $floorCaMetaKey =
                                'FLOOR|'
                                . $floorItem['floor']
                                . '|'
                                . $floorItem['checkpoint'];

                            $floorCaMeta =
                                $displayMeta[$floorCaMetaKey]
                                ?? checkpointDisplayMeta(
                                    (string)$floorItem['checkpoint'],
                                    [],
                                    $checkpointMeta,
                                    []
                                );
                            ?>

                            <td>
                                <span class="priority-badge">
                                    <?= h($floorCaMeta['priority']) ?>
                                </span>
                            </td>

                            <td>
                                <span class="section-badge">
                                    <?= h($floorCaMeta['section']) ?>
                                </span>
                            </td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($floorCaMeta['name']) ?>
                                </div>
                            </td>

                            <td>
                                <span class="text-muted">—</span>
                            </td>

                            <td>
                                <span class="result-badge result-ca">
                                    CA
                                </span>
                            </td>

                            <td class="remark-cell">
                                <?php
                                $floorCaRemark = trim((string)$floorItem['remark']);
                                $floorCaJustification = trim((string)(
                                    $floorItem['ca_remark']
                                    ?? $floorItem['technical_justification']
                                    ?? ''
                                ));
                                ?>

                                <?php if ($floorCaRemark !== ''): ?>
                                    <?= nl2br(h($floorCaRemark)) ?>
                                <?php endif; ?>

                                <?php if ($floorCaJustification !== ''): ?>
                                    <?php if ($floorCaRemark !== ''): ?><br><?php endif; ?>
                                    <?= nl2br(h($floorCaJustification)) ?>
                                <?php endif; ?>

                                <?php if ($floorCaRemark === '' && $floorCaJustification === ''): ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">
                                    <?php
                                    $floorCaPhoto = trim((string)(
                                        $floorItem['photo']
                                        ?? $floorItem['photo_path']
                                        ?? ''
                                    ));
                                    $floorCaVideo = trim((string)(
                                        $floorItem['video']
                                        ?? $floorItem['video_path']
                                        ?? ''
                                    ));

                                    if (
                                        isset($floorItem['evidence'])
                                        && is_array($floorItem['evidence'])
                                    ) {
                                        if (
                                            $floorCaPhoto === ''
                                            && !empty($floorItem['evidence']['photo'])
                                        ) {
                                            $floorCaPhoto = (string)$floorItem['evidence']['photo'];
                                        }

                                        if (
                                            $floorCaVideo === ''
                                            && !empty($floorItem['evidence']['video'])
                                        ) {
                                            $floorCaVideo = (string)$floorItem['evidence']['video'];
                                        }
                                    }
                                    ?>

                                    <?php if ($floorCaPhoto !== ''): ?>
                                        <a href="<?= h(evidenceUrl($floorCaPhoto)) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="evidence-link">
                                            <i class="bi bi-image"></i> Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($floorCaVideo !== ''): ?>
                                        <a href="<?= h(evidenceUrl($floorCaVideo)) ?>"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           class="evidence-link">
                                            <i class="bi bi-camera-video"></i> Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($floorCaPhoto === '' && $floorCaVideo === ''): ?>
                                        <span class="empty-evidence">
                                            No evidence uploaded
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>

                            <td class="ca-owner-decision-cell">
                                <?php
                                $floorOwnerCaDecision =
                                    (isset($floorItem['owner_ca_decision']) && is_array($floorItem['owner_ca_decision']))
                                        ? $floorItem['owner_ca_decision']
                                        : [];

                                $floorDecisionStatus =
                                    strtolower(trim((string)($floorOwnerCaDecision['status'] ?? '')));
                                ?>

                                <?php if ($floorDecisionStatus === 'approved'): ?>

                                    <span class="ca-decision-badge ca-approved">
                                        <i class="bi bi-check-circle"></i>
                                        Owner Approved
                                    </span>

                                    <div class="ca-decision-meta">
                                        Permission recorded
                                        <?php if (!empty($floorOwnerCaDecision['decided_at_ist'])): ?>
                                            <br>
                                            <?= formatIstDate($floorOwnerCaDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($floorDecisionStatus === 'rejected'): ?>

                                    <span class="ca-decision-badge ca-rejected">
                                        <i class="bi bi-x-circle"></i>
                                        Owner Rejected
                                    </span>

                                    <?php if (!empty($floorOwnerCaDecision['reason'])): ?>
                                        <div class="ca-rejection-reason">
                                            <?= nl2br(h($floorOwnerCaDecision['reason'])) ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="ca-decision-meta">
                                        <?php if (!empty($floorOwnerCaDecision['decided_at_ist'])): ?>
                                            <?= formatIstDate($floorOwnerCaDecision['decided_at_ist']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>

                                    <form method="POST"
                                          action=""
                                          class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_approve">
                                        <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                        <input type="hidden" name="ca_ref" value="<?= h($floorItem['checkpoint']) ?>">
                                        <input type="hidden" name="ca_floor" value="<?= h($floorItem['floor']) ?>">

                                        <button type="submit"
                                                class="ca-approve-btn"
                                                onclick="return confirm('Accept this Conditional Acceptance?');">
                                            <i class="bi bi-check-circle"></i>
                                            Accept
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action=""
                                          class="d-inline mt-2"
                                          onsubmit="return prepareCaReject(this);">

                                        <?= csrfField() ?>
                                        <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                        <input type="hidden" name="owner_action" value="ca_reject">
                                        <input type="hidden" name="ca_ref" value="<?= h($floorItem['checkpoint']) ?>">
                                        <input type="hidden" name="ca_floor" value="<?= h($floorItem['floor']) ?>">
                                        <input type="hidden" name="ca_reject_reason" value="">

                                        <button type="submit"
                                                class="ca-reject-btn">
                                            <i class="bi bi-x-circle"></i>
                                            Reject
                                        </button>
                                    </form>

                                <?php endif; ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         SECTION REMARKS
    ====================================================== -->

    <?php if (
        !empty($sectionRemarks)
    ): ?>

        <div class="card-box">

            <div class="card-title">

                <i class="bi bi-chat-left-text"></i>

                Section Remarks

            </div>


            <?php foreach (
                $sectionRemarks
                as $section => $remark
            ): ?>

                <?php

                if (
                    trim(
                        (string)$remark
                    )
                    ===
                    ''
                ) {
                    continue;
                }

                ?>

                <div class="p-3 border-bottom">

                    <strong>
                        <?= h($section) ?>
                    </strong>

                    <div class="remark-cell mt-1">

                        <?= nl2br(
                            h($remark)
                        ) ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         OWNER APPROVAL
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-flag"></i>

            Owner Approval Information

        </div>


        <div class="approval-box">

            <?php if (
                $approvalStatus
                ===
                'rejected'
                &&
                trim($ownerReason)
                !==
                ''
            ): ?>

                <div class="approval-message">

                    <strong>
                        Owner Rejection / Return Reason:
                    </strong>

                    <div class="mt-1">

                        <?= nl2br(
                            h($ownerReason)
                        ) ?>

                    </div>

                </div>

            <?php endif; ?>


            <?php if (
                $job['job_status']
                ===
                'pending_owner'
            ): ?>

                <div class="text-warning fw-semibold">

                    <i class="bi bi-clock me-1"></i>

                    Checklist is waiting for Owner approval.

                </div>

            <?php elseif (
                $approvalStatus
                ===
                'approved'
                ||
                $job['job_status']
                ===
                'approved'
            ): ?>

                <div class="text-success fw-semibold">

                    <i class="bi bi-check-circle me-1"></i>

                    Checklist approval completed.

                </div>

            <?php elseif (
                $approvalStatus
                ===
                'rejected'
            ): ?>

                <div class="text-danger fw-semibold">

                    <i class="bi bi-arrow-return-left me-1"></i>

                    Checklist was returned / rejected by Owner.

                </div>

            <?php else: ?>

                <div class="text-muted">

                    Owner approval status:

                    <strong>
                        <?= h(
                            $approvalStatus
                            ?:
                            'Not Recorded'
                        ) ?>
                    </strong>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         REPORT REQUEST
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-file-earmark-pdf"></i>

            Inspection Report

        </div>


        <div class="report-request-box">


            <?php if (
                !$reportTableExists
            ): ?>

                <div
                    class="report-status-box report-status-progress"
                >

                    <div class="report-status-title">

                        Report Request Module Not Configured

                    </div>

                    <div class="report-status-text">

                        The checklist is saved correctly, but
                        the report_requests table is not available
                        in this database.

                    </div>

                </div>


            <?php elseif (!$reportRequestUnlocked): ?>

                <div class="report-status-box report-status-progress">

                    <div class="report-status-title">

                        <i class="bi bi-lock-fill me-1"></i>

                        Inspection Report Locked

                    </div>

                    <div class="report-status-text">

                        The Inspection Report remains locked until the Final
                        payment is verified by Admin.

                    </div>

                    <div class="alert alert-warning mt-3 mb-0">

                        <i class="bi bi-credit-card me-1"></i>

                        Final payment must be <strong>Verified by Admin</strong>
                        before a report request can be sent or an existing
                        report can be viewed.

                    </div>

                </div>


            <?php elseif (
                !$reportRequest
            ): ?>

                <div class="report-request-header">

                    <div>

                        <div class="report-request-title">

                            Request Inspection Report

                        </div>

                        <div class="report-request-description">

                            Request the final inspection report
                            from the assigned Inspector.

                        </div>

                    </div>

                    <?php if ($reportRequestUnlocked): ?>

                        <div class="small text-success fw-semibold mb-2">
                            <i class="bi bi-unlock-fill me-1"></i>
                            Final payment verified by Admin.
                        </div>

                        <button
                            type="button"
                            class="report-request-btn"
                            onclick="openReportRequestModal();"
                        >

                            <i class="bi bi-file-earmark-arrow-up me-1"></i>

                            Request Report

                        </button>

                    <?php else: ?>

                        <button
                            type="button"
                            class="report-request-btn"
                            disabled
                            title="Final payment verification is required before requesting the report."
                        >

                            <i class="bi bi-lock-fill me-1"></i>

                            Report Request Locked

                        </button>

                    <?php endif; ?>

                </div>

                <?php if (!$reportRequestUnlocked): ?>

                    <div class="alert alert-warning mt-3 mb-0">

                        <i class="bi bi-lock-fill me-1"></i>

                        <strong>Report Request Locked.</strong>

                        Only Final payment verification by Admin is required.
                        As soon as the Final payment is verified, this
                        Report Request will automatically unlock.

                    </div>

                <?php endif; ?>


            <?php elseif (
                $reportStatus
                ===
                'requested'
            ): ?>

                <div
                    class="report-status-box report-status-requested"
                >

                    <div class="report-status-title">

                        <i class="bi bi-clock-history me-1"></i>

                        Report Requested

                    </div>

                    <div class="report-status-text">

                        Your report request has been sent
                        to the assigned Inspector.

                    </div>


                    <?php if (
                        !empty(
                            $reportRequest[
                                'requested_at'
                            ]
                        )
                    ): ?>

                        <div class="small text-muted mt-2">

                            Requested (IST):

                            <?= formatDbUtcToIst(
                                $reportRequest[
                                    'requested_at'
                                ]
                            ) ?>

                        </div>

                    <?php endif; ?>

                </div>


            <?php elseif (
                $reportStatus
                ===
                'in_progress'
            ): ?>

                <div
                    class="report-status-box report-status-progress"
                >

                    <div class="report-status-title">

                        <i class="bi bi-hourglass-split me-1"></i>

                        Report In Progress

                    </div>

                    <div class="report-status-text">

                        The Inspector is currently preparing
                        the inspection report.

                    </div>

                </div>


            <?php elseif (
                $reportStatus
                ===
                'sent_to_owner'
                ||
                $reportStatus
                ===
                'sent'
                ||
                $reportStatus
                ===
                'completed'
            ): ?>

                <div
                    class="report-status-box report-status-sent"
                >

                    <div class="report-status-title">

                        <i class="bi bi-check-circle me-1"></i>

                        Report Available

                    </div>

                    <div class="report-status-text">

                        The Inspector has generated and sent
                        the final inspection report to you.

                    </div>


                    <?php if (
                        $reportFileUrl
                        !==
                        ''
                    ): ?>

                        <a
                            href="<?= h(
                                $reportFileUrl
                            ) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="view-report-btn"
                        >

                            <i class="bi bi-file-earmark-pdf me-1"></i>

                            View Report

                        </a>

                    <?php else: ?>

                        <div
                            class="alert alert-warning mt-3 mb-0"
                        >

                            Report has been marked as sent,
                            but no report file is attached.

                        </div>

                    <?php endif; ?>


                    <?php if (
                        !empty(
                            $reportRequest[
                                'sent_at'
                            ]
                        )
                    ): ?>

                        <div class="small text-muted mt-2">

                            Sent (IST):

                            <?= formatDbUtcToIst(
                                $reportRequest[
                                    'sent_at'
                                ]
                            ) ?>

                        </div>

                    <?php endif; ?>

                </div>


            <?php elseif (
                $reportStatus
                ===
                'cancelled'
            ): ?>

                <div
                    class="report-status-box report-status-cancelled"
                >

                    <div class="report-status-title">

                        <i class="bi bi-x-circle me-1"></i>

                        Report Request Cancelled

                    </div>

                    <div class="report-status-text">

                        The previous report request was cancelled.

                    </div>


                    <?php if ($reportRequestUnlocked): ?>

                        <button
                            type="button"
                            class="report-request-btn mt-3"
                            onclick="openReportRequestModal();"
                        >

                            <i class="bi bi-arrow-repeat me-1"></i>

                            Request Report Again

                        </button>

                    <?php else: ?>

                        <button
                            type="button"
                            class="report-request-btn mt-3"
                            disabled
                            title="Final payment verification is required."
                        >

                            <i class="bi bi-lock-fill me-1"></i>

                            Report Request Locked

                        </button>

                        <div class="alert alert-warning mt-3 mb-0">

                            Final payment must be verified by Admin before requesting the report again.

                        </div>

                    <?php endif; ?>

                </div>


            <?php else: ?>

                <div
                    class="report-status-box report-status-progress"
                >

                    <div class="report-status-title">

                        Report Status:

                        <?= h(
                            ucfirst(
                                $reportStatus
                            )
                        ) ?>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         SERVICE COMPLETION & CERTIFICATE
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-patch-check"></i>

            <?= h($isSci ? 'SCI Completion & Certificate' : ($isSat ? 'SAT Completion & Certificate' : 'PAT Completion & Certificate')) ?>

        </div>


        <div class="action-panel">

            <?php if (
                $actionSuccess !== ''
            ): ?>

                <div class="alert alert-success">

                    <i class="bi bi-check-circle me-1"></i>

                    <?= h($actionSuccess) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $actionError !== ''
            ): ?>

                <div class="alert alert-danger">

                    <i class="bi bi-exclamation-circle me-1"></i>

                    <?= h($actionError) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $patStatus ===
                'closed'
            ): ?>

                <div class="action-status closed">

                    <strong>

                        <i class="bi bi-check-circle me-1"></i>

                        <?= h($isSci ? 'SCI Closed' : ($isSat ? 'SAT Closed' : 'PAT Closed')) ?>

                    </strong>

                    <div class="action-meta">

                        <?= h($isSci ? 'SCI completed on (IST):' : ($isSat ? 'SAT completed on (IST):' : 'PAT completed on (IST):')) ?>

                        <strong>

                            <?= formatIstDate(
                                $patCompletedAt
                            ) ?>

                        </strong>

                    </div>

                </div>

            <?php else: ?>

                <div class="action-status">

                    <strong>
                        <?= $isSci ? 'SCI' : ($isSat ? 'SAT' : 'PAT') ?> is not closed yet.
                    </strong>

                    <div class="action-help">

                        <?= h(
                            $isSci
                                ? 'SCI can be closed only after the final inspection report has been sent by the Inspector '
                                : ($isSat
                                    ? 'SAT can be closed only after the final inspection report has been sent by the Inspector '
                                    : 'PAT can be closed only after the final inspection report has been sent by the Inspector ')
                        ) ?>

                    </div>

                </div>


                <div class="action-buttons">

                    <form
                        method="POST"
                        onsubmit="return confirm('<?= h($isSat ? 'Are you sure you want to mark this SAT as Complete and close the SAT? This action should be done only after the final inspection report has been received.' : 'Are you sure you want to mark this PAT as Complete and close the PAT? This action should be done only after the final inspection report has been received.') ?>');"
                    >

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="job_id"
                            value="<?= (int)$jobId ?>"
                        >

                        <input
                            type="hidden"
                            name="owner_action"
                            value="service_complete"
                        >

                        <?php
                        $canCompleteService =
                            in_array(
                                $reportStatus,
                                [
                                    'sent_to_owner',
                                    'sent',
                                    'completed'
                                ],
                                true
                            );
                        ?>

                        <button
                            type="submit"
                            class="pat-action-btn pat-complete-btn"
                            <?= !$canCompleteService
                                ? 'disabled'
                                : '' ?>
                        >

                            <i class="bi bi-check2-circle me-1"></i>

                            <?= $isSci ? 'SCI' : ($isSat ? 'SAT' : 'PAT') ?> Complete

                        </button>

                    </form>

                </div>

            <?php endif; ?>


            <?php if (
                $patStatus ===
                'closed'
            ): ?>

                <?php if (
                    $certificateStatus ===
                    'requested'
                ): ?>

                    <div class="action-status requested">

                        <strong>

                            <i class="bi bi-clock-history me-1"></i>

                            Certificate Requested

                        </strong>

                        <div class="action-meta">

                            Certificate request has been sent
                            to Admin for generation.

                        </div>

                    </div>


                <?php elseif (
                    $certificateStatus ===
                    'generated'
                ): ?>

                    <div class="action-status generated">

                        <strong>

                            <i class="bi bi-file-earmark-check me-1"></i>

                            Certificate Generated

                        </strong>

                        <?php if (
                            trim(
                                $certificateNo
                            )
                            !==
                            ''
                        ): ?>

                            <div class="action-meta">

                                Certificate No.:

                                <strong>
                                    <?= h(
                                        $certificateNo
                                    ) ?>
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>


                <?php elseif (
                    $certificateStatus ===
                    'sent'
                ): ?>

                    <div class="action-status closed">

                        <strong>

                            <i class="bi bi-send-check me-1"></i>

                            Certificate Sent to Owner

                        </strong>

                        <?php if (
                            trim(
                                $certificateNo
                            )
                            !==
                            ''
                        ): ?>

                            <div class="action-meta">

                                Certificate No.:

                                <strong>
                                    <?= h(
                                        $certificateNo
                                    ) ?>
                                </strong>

                            </div>

                        <?php endif; ?>

                    </div>


                <?php else: ?>

                    <div class="action-status">

                        <strong>

                            <i class="bi bi-award me-1"></i>

                            Certificate Ready to Request

                        </strong>

                        <div class="action-help">

                            <?= $isSci ? 'SCI' : ($isSat ? 'SAT' : 'PAT') ?> is closed. You can now request
                            the official certificate from Admin.

                        </div>

                    </div>


                    <form
                        method="POST"
                        onsubmit="return confirm('Send certificate request to Admin?');"
                    >

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="job_id"
                            value="<?= (int)$jobId ?>"
                        >

                        <input
                            type="hidden"
                            name="owner_action"
                            value="request_certificate"
                        >

                        <button
                            type="submit"
                            class="pat-action-btn certificate-request-btn"
                        >

                            <i class="bi bi-award me-1"></i>

                            Request for Certificate

                        </button>

                    </form>

                <?php endif; ?>

            <?php endif; ?>

        </div>

    </div>


</div>

</div>


<!-- =========================================================
     REPORT REQUEST MODAL
========================================================= -->

<?php if ($reportTableExists): ?>

<div
    class="modal fade"
    id="reportRequestModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <div class="modal-header">

                <h5 class="modal-title">

                    <i class="bi bi-file-earmark-pdf me-2"></i>

                    Request Inspection Report

                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form
                method="POST"
                action="request-report.php"
            >

                <?= csrfField() ?>


                <input
                    type="hidden"
                    name="job_id"
                    value="<?= (int)$jobId ?>"
                >


                <div class="modal-body">


                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            Lift
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= h(
                                $displayAsset
                            ) ?>"
                            readonly
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            Inspector
                        </label>

                        <input
                            type="text"
                            class="form-control"
                            value="<?= h(
                                $job['inspector_name']
                                ?:
                                'Not Assigned'
                            ) ?>"
                            readonly
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label fw-semibold">
                            Message to Inspector
                        </label>

                        <textarea
                            name="message"
                            class="form-control"
                            rows="4"
                            maxlength="2000"
                            required
                        >Please prepare and send the final inspection report for this asset.</textarea>

                    </div>


                    <div class="alert alert-info mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        The request will be sent to the assigned Inspector.

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-send me-1"></i>

                        Send Report Request

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>


<script>
function prepareCaReject(form)
{
    const reason = window.prompt(
        'Please enter the reason for rejecting this Conditional Acceptance:'
    );

    if (reason === null) {
        return false;
    }

    const trimmed = reason.trim();

    if (trimmed === '') {
        alert('Rejection reason is required.');
        return false;
    }

    const input =
        form.querySelector(
            'input[name="ca_reject_reason"]'
        );

    if (!input) {
        alert('Unable to prepare the rejection reason.');
        return false;
    }

    input.value = trimmed;

    return confirm(
        'Reject this Conditional Acceptance and record this reason?'
    );
}


function openReportRequestModal()
{
    <?php if (!$reportRequestUnlocked): ?>
        alert('Final payment must be verified by Admin before requesting the inspection report.');
        return;
    <?php endif; ?>

    const modalElement =
        document.getElementById(
            'reportRequestModal'
        );

    if (!modalElement) {
        return;
    }

    if (
        typeof bootstrap !== 'undefined'
    ) {

        const modal =
            bootstrap.Modal.getOrCreateInstance(
                modalElement
            );

        modal.show();

    } else {

        alert(
            'Unable to open report request window.'
        );
    }
}
</script>



<script>
/*
 * =========================================================
 * FINAL PAYMENT AUTO-REFRESH
 * ---------------------------------------------------------
 * If the report is currently locked, periodically reload this
 * page so an Admin verification becomes visible automatically.
 *
 * No payment data is changed by this script.
 * Once the server reports the report as unlocked, polling stops.
 * =========================================================
 */
(function () {
    'use strict';

    var reportLocked = <?= $reportRequestUnlocked ? 'false' : 'true' ?>;

    if (!reportLocked) {
        return;
    }

    var refreshTimer = window.setInterval(function () {
        /*
         * Preserve the current job_id and all existing query
         * parameters while forcing a fresh server-side check.
         */
        var url = new URL(window.location.href);

        url.searchParams.set('_payment_check', String(Date.now()));

        window.location.replace(url.toString());

    }, 15000);

    window.addEventListener('beforeunload', function () {
        if (refreshTimer) {
            window.clearInterval(refreshTimer);
        }
    });
})();


/* =========================================================
   OWNER NC / CA PDF DOWNLOAD
   Opens a clean print view containing only the selected
   NC or CA checklist. In the browser print dialog choose
   "Save as PDF" to download the PDF file.
========================================================= */
function downloadOwnerSectionPdf(sectionId, fileName)
{
    const source = document.getElementById(sectionId);

    if (!source) {
        alert('Checklist section could not be found.');
        return;
    }

    /*
     * IMPORTANT:
     * The live Owner page is NOT changed.
     * We clone only the selected NC/CA section and make
     * Evidence + Owner Confirmation + Section columns
     * disappear ONLY from this PDF/print copy.
     *
     * CA Owner Decision is intentionally kept.
     */
    const clone = source.cloneNode(true);

    clone.querySelectorAll(
        '.no-pdf, form, button, input, textarea, select'
    ).forEach(function (el) {
        el.remove();
    });

    /*
     * Remove the original colgroup definitions from the PDF clone.
     * The live page keeps its original column widths, but the PDF
     * must calculate the remaining columns across the complete A4 width.
     */
    clone.querySelectorAll('table colgroup').forEach(function (colgroup) {
        colgroup.remove();
    });

    /* CA table uses wider live-page rules. Normalize those rules only in the PDF clone. */
    clone.querySelectorAll('.ca-table').forEach(function (table) {
        table.style.width = '100%';
        table.style.minWidth = '0';
        table.style.maxWidth = 'none';
        table.style.height = 'auto';
        table.style.minHeight = '0';
        table.style.maxHeight = 'none';
        table.style.tableLayout = 'fixed';
        table.style.margin = '0';
        table.style.breakBefore = 'avoid';
        table.style.pageBreakBefore = 'avoid';
    });

    clone.querySelectorAll('.ca-table .ca-owner-decision-cell, .ca-table .remark-cell').forEach(function (cell) {
        cell.style.minWidth = '0';
        cell.style.maxWidth = 'none';
        cell.style.width = 'auto';
        cell.style.height = 'auto';
    });

    /*
     * Remove complete table columns from the PDF clone by
     * matching their header text.
     *
     * Removed only in PDF:
     *  - Evidence
     *  - Owner Confirmation
     *  - Section
     *
     * Owner Decision remains.
     */
    clone.querySelectorAll('table').forEach(function (table) {

        const headerRows = table.querySelectorAll('thead tr');

        if (!headerRows.length) {
            return;
        }

        const headerCells = Array.from(
            headerRows[headerRows.length - 1].children
        );

        const removeIndexes = [];

        headerCells.forEach(function (cell, index) {
            const headerText = (cell.innerText || '')
                .trim()
                .replace(/\s+/g, ' ')
                .toLowerCase();

            if (
                headerText === 'evidence'
                || headerText === 'owner confirmation'
                || headerText === 'section'
            ) {
                removeIndexes.push(index);
            }
        });

        if (!removeIndexes.length) {
            return;
        }

        table.querySelectorAll('tr').forEach(function (row) {
            const cells = Array.from(row.children);

            removeIndexes
                .slice()
                .sort(function (a, b) {
                    return b - a;
                })
                .forEach(function (index) {
                    if (cells[index]) {
                        cells[index].remove();
                    }
                });
        });

        table.querySelectorAll(
            '.evidence-links, .evidence-cell, .owner-confirm-cell'
        ).forEach(function (el) {
            el.remove();
        });
    });

    /*
     * Safety cleanup: these elements are allowed on the live page,
     * but never appear in the generated PDF copy.
     */
    clone.querySelectorAll(
        '.evidence-links, .evidence-cell, .owner-confirm-cell, .owner-pending-badge'
    ).forEach(function (el) {
        el.remove();
    });

    const printWindow =
        window.open('', '_blank', 'width=1200,height=900');

    if (!printWindow) {
        alert('Please allow pop-ups for this site to download the PDF.');
        return;
    }

    const serviceTitle = <?= json_encode(
        $isSci ? 'SCI' : ($isSat ? 'SAT' : 'PAT'),
        JSON_UNESCAPED_UNICODE
    ) ?>;

    /* PDF-only asset information. Live Owner page remains unchanged. */
    const pdfAssetId = <?= json_encode((string)($displayAsset ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfAssetName = <?= json_encode((string)($job['asset_name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfAssetLocation = <?= json_encode((string)($job['location'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfAssetType = <?= json_encode((string)($job['asset_type'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfAssetCategory = <?= json_encode((string)($job['category'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfServiceName = <?= json_encode((string)($job['service_name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfSafNo = <?= json_encode((string)($job['saf_no'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const pdfPsgaNo = <?= json_encode((string)($job['psga_no'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    const html = `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapePdfTitle(fileName)}</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
@page { size: A4 landscape; margin: 8mm; }
* { box-sizing: border-box; }
html, body {
    width:100%;
    margin:0;
    padding:0;
}
body {
    font-family: Arial, Helvetica, sans-serif;
    color:#111;
    font-size:11.5px;
    line-height:1.25;
}
h1 { font-size:20px; margin:0 0 5px; }
h2 { font-size:14px; margin:0 0 10px; }
.pdf-meta {
    width:100%;
    margin-bottom:12px;
    padding-bottom:8px;
    border-bottom:1px solid #777;
}
.asset-info-box {
    width:100%;
    margin:6px 0 10px;
    padding:7px 8px;
    border:1px solid #777;
    background:#f7f7f7;
    border-radius:3px;
}
.asset-info-title {
    font-size:10.5px;
    font-weight:700;
    margin-bottom:5px;
}
.asset-info-grid {
    display:grid;
    grid-template-columns:repeat(4, minmax(0, 1fr));
    gap:4px 12px;
}
.asset-info-item {
    font-size:8.8px;
    line-height:1.2;
    overflow-wrap:anywhere;
}
.asset-info-item strong { font-weight:700; }
.card-box,
.card-box > *,
.table-wrap {
    width:100% !important;
    max-width:none !important;
}
.card-box {
    margin:0 !important;
    padding:0 !important;
}
.card-title {
    width:100%;
    font-size:16px;
    font-weight:700;
    margin:0 0 9px;
}
.table-wrap {
    overflow:visible !important;
    margin:0 !important;
    padding:0 !important;
    break-before:auto !important;
    page-break-before:auto !important;
    break-inside:auto !important;
    page-break-inside:auto !important;
}
table {
    display:table !important;
    width:100% !important;
    min-width:0 !important;
    max-width:none !important;
    height:auto !important;
    min-height:0 !important;
    max-height:none !important;
    border-collapse:collapse;
    table-layout:fixed !important;
    margin:0 !important;
    padding:0 !important;
    break-before:avoid !important;
    page-break-before:avoid !important;
    break-after:auto !important;
    page-break-after:auto !important;
}

/* CA must print exactly like NC: heading followed immediately by the table. */
.card-title + .table-wrap {
    display:block !important;
    width:100% !important;
    height:auto !important;
    min-height:0 !important;
    max-height:none !important;
    margin-top:0 !important;
    padding-top:0 !important;
    break-before:avoid !important;
    page-break-before:avoid !important;
    break-inside:auto !important;
    page-break-inside:auto !important;
}

tbody, tbody tr {
    height:auto !important;
    min-height:0 !important;
    max-height:none !important;
}
/*
 * Six columns remain after PDF-only removal of Evidence, Owner Confirmation
 * and Section. These percentages total exactly 100%, so NC and CA both use
 * the complete printable A4 width.
 */
table th:nth-child(1), table td:nth-child(1) { width:12% !important; }
table th:nth-child(2), table td:nth-child(2) { width:9% !important; }
table th:nth-child(3), table td:nth-child(3) { width:29% !important; }
table th:nth-child(4), table td:nth-child(4) { width:22% !important; }
table th:nth-child(5), table td:nth-child(5) { width:9% !important; }
table th:nth-child(6), table td:nth-child(6) { width:19% !important; }
th, td {
    border:1px solid #666;
    padding:6px 7px;
    vertical-align:middle;
    word-break:normal;
    overflow-wrap:anywhere;
}
th {
    background:#e9ecef;
    font-size:11.5px;
    font-weight:700;
    text-align:left;
}
td {
    font-size:11px;
}
thead {
    display:table-header-group;
}
tbody {
    display:table-row-group;
}
tr {
    break-inside:auto;
    page-break-inside:auto;
}
td, th {
    break-inside:avoid;
    page-break-inside:avoid;
}
a {
    color:#111;
    text-decoration:none;
}
.ca-decision-badge,
.result-badge,
.result-nc,
.result-ca {
    display:inline-block;
    padding:3px 6px;
    border:1px solid #777;
    border-radius:4px;
    font-weight:700;
    white-space:nowrap;
}
.no-nc-box {
    width:100%;
    border:1px solid #aaa;
    padding:12px;
}
.evidence-links,
.evidence-cell,
.owner-confirm-cell,
.owner-pending-badge {
    display:none !important;
}
@media print {
    .no-pdf {
        display:none !important;
    }
    .card-box, .table-wrap, table {
        width:100% !important;
        max-width:none !important;
    }
}
</style>
</head>
<body>
<div class="pdf-meta">
    <h1>NLETA Inspection Checklist</h1>
    <h2>${serviceTitle} — ${escapePdfText(source.querySelector('.card-title')?.innerText || '')}</h2>
    <div>Job ID: <?= (int)$jobId ?> &nbsp; | &nbsp; Generated: ${new Date().toLocaleString('en-IN')}</div>

    <div class="asset-info-box">
        <div class="asset-info-title">Asset Information</div>
        <div class="asset-info-grid">
            <div class="asset-info-item"><strong>Asset ID:</strong> ${escapePdfText(pdfAssetId || '—')}</div>
            <div class="asset-info-item"><strong>Asset Name:</strong> ${escapePdfText(pdfAssetName || '—')}</div>
            <div class="asset-info-item"><strong>Location:</strong> ${escapePdfText(pdfAssetLocation || '—')}</div>
            <div class="asset-info-item"><strong>Asset Type:</strong> ${escapePdfText(pdfAssetType || '—')}</div>
            <div class="asset-info-item"><strong>Category:</strong> ${escapePdfText(pdfAssetCategory || '—')}</div>
            <div class="asset-info-item"><strong>Service:</strong> ${escapePdfText(pdfServiceName || serviceTitle || '—')}</div>
        </div>
    </div>
</div>
${clone.outerHTML}
</body>
</html>`;

    printWindow.document.open();
    printWindow.document.write(html);
    printWindow.document.close();

    const startPrint = function () {
        printWindow.focus();
        printWindow.print();

        setTimeout(function () {
            printWindow.close();
        }, 1000);
    };

    if (
        printWindow.document.fonts &&
        printWindow.document.fonts.ready
    ) {
        printWindow.document.fonts.ready.then(startPrint);
    } else {
        setTimeout(startPrint, 500);
    }
}
function escapePdfText(value)
{
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapePdfTitle(value)
{
    return escapePdfText(value).replace(/[^a-zA-Z0-9_-]+/g, '_');
}
</script>

<?php include('../includes/footer.php'); ?>