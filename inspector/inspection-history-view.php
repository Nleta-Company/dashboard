<?php
/* =========================================================
   NLETA INSPECTOR — HISTORY / VIEW CHECKLIST
   ---------------------------------------------------------
   PURPOSE
   -------
   Opens the saved checklist for ONE historical inspection
   instead of redirecting to inspections.php.

   SUPPORTED:
     LIFT:
       PAT / SAT / SCI
     ESCALATOR / TRAVELLATOR:
       PAT / SAT / SCI

   READ-ONLY:
     - No checklist data is changed.
     - No submission/update is possible.
     - Original saved checklist JSON is displayed.
     - Saved evidence from saf_checklist_evidence is displayed.
     - Floor-wise PAT records are displayed.
     - SAT matrix / acceptance data is displayed when present.
     - Owner approval data is displayed when present.

   SECURITY:
     - Logged-in Inspector only.
     - job_id must belong to an assignment of that Inspector.
     - asset_id, when supplied, must match the selected job.

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


/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatIST($value, string $format = 'd / m / Y h:i A'): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        /*
         * Application timestamps are normally stored as UTC.
         * Display everything explicitly in IST.
         */
        $dt = new DateTime(
            (string)$value,
            new DateTimeZone('UTC')
        );

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return h($dt->format($format));

    } catch (Throwable $e) {
        return h($value);
    }
}

function decodeChecklist($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function normalizeResult($value): string
{
    $value = strtoupper(trim((string)$value));

    return match ($value) {
        'OK', 'PASS', 'COMPLIED' => 'C',
        'FAIL', 'NON-COMPLIED',
        'NON COMPLIED',
        'NON_COMPLIED',
        'NOT COMPLIED' => 'NC',
        'NOT APPLICABLE',
        'NOT-APPLICABLE' => 'NA',
        'CONDITIONAL ACCEPTANCE' => 'CA',
        default => $value,
    };
}

function resultClass($value): string
{
    return match (normalizeResult($value)) {
        'C'  => 'result-c',
        'NC' => 'result-nc',
        'NA' => 'result-na',
        'CA' => 'result-ca',
        default => 'result-other',
    };
}

function resultText($value): string
{
    $result = normalizeResult($value);

    return $result !== '' ? $result : '—';
}

function evidenceUrl(string $storedPath): string
{
    $storedPath = str_replace('\\', '/', trim($storedPath));

    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath)) {
        return $storedPath;
    }

    return '../' . ltrim($storedPath, '/');
}

function cleanDisplayText($value): string
{
    if (is_array($value) || is_object($value)) {
        return '';
    }

    return trim((string)$value);
}

function firstNonEmpty(array $row, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row)) {
            $value = cleanDisplayText($row[$key]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function refFromRow(array $row, string $fallback = ''): string
{
    return firstNonEmpty(
        $row,
        [
            'ref',
            'ref_code',
            'checkpoint_id',
            'checkpoint',
            'id',
            'code',
            'key'
        ]
    ) ?: $fallback;
}

function textFromRow(array $row, string $fallback = ''): string
{
    return firstNonEmpty(
        $row,
        [
            'text',
            'checkpoint',
            'checkpoint_name',
            'checkpoint_text',
            'name',
            'description',
            'question',
            'label',
            'title'
        ]
    ) ?: $fallback;
}

function priorityFromRow(array $row): string
{
    return firstNonEmpty(
        $row,
        [
            'priority',
            'Priority',
            'checkpoint_priority'
        ]
    );
}


/**
 * Build a read-only priority lookup from the Inspector's saved
 * checkpoint_metadata block.
 *
 * This is important for older/newer checklist payloads where the
 * individual results[] row may not contain priority, while the
 * checklist-level metadata does.
 */
function buildSavedPriorityMap($data, array &$map = [], int $depth = 0): array
{
    if (!is_array($data) || $depth > 12) {
        return $map;
    }

    foreach ($data as $key => $value) {

        if (!is_array($value)) {
            continue;
        }

        $ref = firstNonEmpty(
            $value,
            [
                'checkpoint_id',
                'ref',
                'ref_code',
                'code',
                'id',
                'key'
            ]
        );

        $priority = firstNonEmpty(
            $value,
            [
                'priority',
                'Priority',
                'checkpoint_priority'
            ]
        );

        /*
         * When metadata is stored as:
         * "E-SAT-001" => ["priority" => "P1", ...]
         * the array key itself is the checkpoint reference.
         */
        if ($ref === '' && is_string($key)) {
            $keyText = trim($key);

            if (
                preg_match(
                    '/^(?:E-)?(?:PAT|SAT|SCI)-\d{3}$/i',
                    $keyText
                )
            ) {
                $ref = $keyText;
            }
        }

        if ($ref !== '' && $priority !== '') {
            $map[$ref] = $priority;
            $map[strtoupper($ref)] = $priority;
        }

        buildSavedPriorityMap(
            $value,
            $map,
            $depth + 1
        );
    }

    return $map;
}

function applySavedPriority(
    array $item,
    string $ref,
    array $savedPriorityMap
): array {
    /*
     * Existing result-level priority always wins because the
     * Inspector result is the historical source of truth.
     */
    if (priorityFromRow($item) !== '') {
        return $item;
    }

    $ref = trim($ref);

    if ($ref === '') {
        return $item;
    }

    $priority =
        $savedPriorityMap[$ref]
        ?? $savedPriorityMap[strtoupper($ref)]
        ?? '';

    if ($priority !== '') {
        $item['priority'] = $priority;
    }

    return $item;
}

function checkpointAreaFromRow(array $row, string $ref = '', string $serviceCode = '', bool $isEscalator = false): string
{
    $saved = firstNonEmpty(
        $row,
        [
            'area',
            'checkpoint_area',
            'section',
            'section_name',
            'section_title',
            'group'
        ]
    );

    if ($saved !== '') {
        return $saved;
    }

    $ref = strtoupper(trim($ref));
    $serviceCode = strtoupper(trim($serviceCode));

    if ($isEscalator && $serviceCode === 'PAT' && preg_match('/^E-PAT-(\d{3})$/', $ref, $m)) {
        $n = (int)$m[1];
        return match (true) {
            $n >= 1 && $n <= 6   => 'A — LANDING & PASSENGER SAFETY',
            $n >= 7 && $n <= 12  => 'B — LIGHTING & PASSENGER INTERFACE',
            $n >= 13 && $n <= 18 => 'C — BOTTOM PIT SAFETY',
            $n >= 22 && $n <= 23 => 'D — INCLINED STRUCTURE SAFETY',
            $n === 24            => 'E — CUSTOMER & ELECTRICAL SAFETY',
            $n >= 25 && $n <= 43 => 'F — SAFETY SWITCHES & ESCALATOR PROTECTION',
            $n >= 44 && $n <= 50 => 'G — LIGHTING, BRUSH, PIT & MACHINE CONDITION',
            default              => ''
        };
    }

    return '';
}

function remarkFromRow(array $row): string
{
    return firstNonEmpty(
        $row,
        [
            'remark',
            'engineering_remark',
            'observation',
            'comment',
            'comments'
        ]
    );
}

function caRemarkFromRow(array $row): string
{
    return firstNonEmpty(
        $row,
        [
            'ca_remark',
            'conditional_acceptance_remark',
            'conditional_acceptance_condition',
            'condition',
            'acceptance_remark'
        ]
    );
}

function technicalJustificationFromRow(array $row): string
{
    return firstNonEmpty(
        $row,
        [
            'technical_justification',
            'justification'
        ]
    );
}

function evidenceFromRow(array $row): array
{
    $out = [
        'photo' => '',
        'video' => '',
        'document' => ''
    ];

    foreach (['photo', 'video', 'document'] as $key) {
        if (isset($row[$key]) && !is_array($row[$key])) {
            $out[$key] = trim((string)$row[$key]);
        }
    }

    if (
        isset($row['evidence'])
        && is_array($row['evidence'])
    ) {
        foreach (['photo', 'video', 'document'] as $key) {
            if (
                $out[$key] === ''
                && isset($row['evidence'][$key])
                && !is_array($row['evidence'][$key])
            ) {
                $out[$key] = trim(
                    (string)$row['evidence'][$key]
                );
            }
        }
    }

    return $out;
}

function isCheckpointRow(array $row): bool
{
    $hasResult =
        array_key_exists('result', $row)
        || array_key_exists('status', $row)
        || array_key_exists('acceptance_result', $row);

    $hasIdentity =
        array_key_exists('ref', $row)
        || array_key_exists('ref_code', $row)
        || array_key_exists('checkpoint_id', $row)
        || array_key_exists('checkpoint_name', $row)
        || array_key_exists('checkpoint_text', $row)
        || array_key_exists('question', $row);

    return $hasResult && $hasIdentity;
}

function isListArray(array $array): bool
{
    if ($array === []) {
        return true;
    }

    return array_keys($array) === range(0, count($array) - 1);
}


/* =========================================================
   CURRENT INSPECTOR / REQUEST
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$jobId = (int)(
    $_GET['job_id']
    ?? 0
);

$requestedAssetId = (int)(
    $_GET['asset_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: ../login.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: history.php');
    exit;
}


/* =========================================================
   LOAD HISTORICAL JOB
   ---------------------------------------------------------
   inspector_assignments is the Inspector ownership authority.
========================================================= */

$stmt = $conn->prepare("
    SELECT
        ia.id AS assignment_id,
        ia.job_id,
        ia.asset_id AS assignment_asset_id,
        ia.inspector_id,
        ia.owner_id AS assignment_owner_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,
        ia.submitted_at,
        ia.completed_at,
        ia.remarks AS assignment_remarks,

        mj.id AS maintenance_job_id,
        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.job_type,
        mj.created_at AS job_created_at,
        mj.updated_at AS job_updated_at,
        mj.checklist,
        mj.owner_remark,
        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        s.request_id,
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
    die(
        '<div class="history-error">'
        . '<h3>Unable to load inspection history</h3>'
        . '<p>' . h($conn->error) . '</p>'
        . '</div>'
    );
}

$stmt->bind_param(
    'ii',
    $jobId,
    $inspectorId
);

if (!$stmt->execute()) {
    $error = $stmt->error;
    $stmt->close();

    die(
        '<div class="history-error">'
        . '<h3>Unable to load inspection history</h3>'
        . '<p>' . h($error) . '</p>'
        . '</div>'
    );
}

$job = $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$job) {
    die(
        '<div class="history-error">'
        . '<h3>Inspection record not found</h3>'
        . '<p>This inspection does not belong to the logged-in Inspector.</p>'
        . '</div>'
    );
}


/* =========================================================
   ASSET AUTHORIZATION
========================================================= */

$actualAssetId = (int)(
    $job['asset_id']
    ?? 0
);

if (
    $requestedAssetId > 0
    && $actualAssetId > 0
    && $requestedAssetId !== $actualAssetId
) {
    die(
        '<div class="history-error">'
        . '<h3>Invalid Asset</h3>'
        . '<p>The selected asset does not belong to this inspection.</p>'
        . '</div>'
    );
}


/* =========================================================
   CHECKLIST JSON
========================================================= */

$checklist = decodeChecklist(
    $job['checklist'] ?? ''
);

/*
 * Inspector checklist saves can keep Priority either directly on
 * each result row or inside the checklist-level checkpoint_metadata.
 * Load both forms so historical records display the exact saved
 * Inspector Priority.
 */
$savedPriorityMap = buildSavedPriorityMap(
    $checklist['checkpoint_metadata'] ?? []
);

$serviceCode = strtoupper(
    trim(
        (string)(
            $job['service_code']
            ?? $checklist['service_code']
            ?? ''
        )
    )
);

$serviceName = trim(
    (string)(
        $job['service_name']
        ?? $checklist['service_name']
        ?? ''
    )
);

$module = strtoupper(
    trim(
        (string)(
            $checklist['module']
            ?? $serviceCode
            ?? ''
        )
    )
);

$checklistCode = trim(
    (string)(
        $checklist['checklist_code']
        ?? ''
    )
);

$revision = trim(
    (string)(
        $checklist['checklist_revision']
        ?? $checklist['revision']
        ?? 'Rev.1'
    )
);

$finalStatus = trim(
    (string)(
        $checklist['final_status']
        ?? ''
    )
);


/* =========================================================
   SERVICE / ASSET DISPLAY
========================================================= */

$assetType = trim(
    (string)(
        $job['asset_type']
        ?? ''
    )
);

$isEscalator =
    str_contains(
        strtolower($assetType),
        'escalator'
    )
    ||
    str_contains(
        strtolower($assetType),
        'travellator'
    );

$assetFamily = $isEscalator
    ? 'Escalator / Travellator'
    : 'Lift / Elevator';

$isLift = !$isEscalator;


/* =========================================================
   DIRECT CHECKLIST ROUTING
   ---------------------------------------------------------
   This history page is now directly connected with every
   production checklist subtype.

   Lift:
     PAT -> pat-checklist.php
     SAT -> sat-checklist.php
     SCI -> sci-checklist.php

   Escalator / Travellator:
     PAT -> escalator-pat-checklist.php
     SAT -> escalator-sat-checklist.php
     SCI -> escalator-sci-checklist.php

   Both job_id and assignment_id are preserved.
   Asset type + service are used together so an Escalator
   inspection can never open the Lift checklist.
========================================================= */

$directChecklistPage = '';

if ($isEscalator) {

    switch ($serviceCode) {
        case 'PAT':
            $directChecklistPage = 'escalator-pat-checklist.php';
            break;

        case 'SAT':
            $directChecklistPage = 'escalator-sat-checklist.php';
            break;

        case 'SCI':
            $directChecklistPage = 'escalator-sci-checklist.php';
            break;
    }

} elseif ($isLift) {

    switch ($serviceCode) {
        case 'PAT':
            $directChecklistPage = 'pat-checklist.php';
            break;

        case 'SAT':
            $directChecklistPage = 'sat-checklist.php';
            break;

        case 'SCI':
            $directChecklistPage = 'sci-checklist.php';
            break;
    }
}

$directChecklistUrl = '';

if ($directChecklistPage !== '') {
    $directChecklistUrl =
        $directChecklistPage
        . '?'
        . http_build_query([
            'assignment_id' => (int)($job['assignment_id'] ?? 0),
            'job_id'        => (int)$jobId,
            'asset_id'      => (int)$actualAssetId
        ], '', '&', PHP_QUERY_RFC3986);
}

if ($serviceCode === 'PAT') {
    $displayChecklistTitle = $isEscalator
        ? 'Escalator / Travellator PAT Checklist'
        : 'Lift / Elevator PAT Checklist';

    $defaultChecklistCode = $isEscalator
        ? 'ECHK-01'
        : 'CHK-01';

} elseif ($serviceCode === 'SAT') {
    $displayChecklistTitle = $isEscalator
        ? 'Escalator / Travellator SAT Checklist'
        : 'Lift / Elevator SAT Checklist';

    $defaultChecklistCode = $isEscalator
        ? 'ECHK-02'
        : 'CHK-02';

} elseif ($serviceCode === 'SCI') {
    $displayChecklistTitle = $isEscalator
        ? 'Escalator / Travellator SCI Checklist'
        : 'Lift / Elevator SCI Checklist';

    $defaultChecklistCode = $isEscalator
        ? 'ECHK-03'
        : 'CHK-03';

} else {
    $displayChecklistTitle = 'Inspection Checklist';
    $defaultChecklistCode = '';
}

if ($checklistCode === '') {
    $checklistCode = $defaultChecklistCode;
}

if ($serviceName === '') {
    $serviceName = $serviceCode ?: 'Inspection';
}


/* =========================================================
   LOAD DATABASE EVIDENCE
========================================================= */

$databaseEvidence = [];

$tableCheck = $conn->query("
    SHOW TABLES LIKE 'saf_checklist_evidence'
");

if (
    $tableCheck
    && $tableCheck->num_rows > 0
) {
    $evidenceStmt = $conn->prepare("
        SELECT
            ref_code,
            result,
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
                 * Keep the first row as the primary database
                 * evidence row for a checkpoint.
                 */
                if (!isset($databaseEvidence[$ref])) {
                    $databaseEvidence[$ref] = [
                        'result' =>
                            (string)(
                                $evidenceRow['result']
                                ?? ''
                            ),
                        'photo' =>
                            (string)(
                                $evidenceRow['photo_path']
                                ?? ''
                            ),
                        'video' =>
                            (string)(
                                $evidenceRow['video_path']
                                ?? ''
                            ),
                        'document' =>
                            (string)(
                                $evidenceRow['document_path']
                                ?? ''
                            ),
                    ];
                }
            }
        }

        $evidenceStmt->close();
    }
}


/* =========================================================
   OWNER APPROVAL
========================================================= */

$ownerApproval = (
    isset($checklist['owner_approval'])
    && is_array($checklist['owner_approval'])
)
    ? $checklist['owner_approval']
    : [];


/* =========================================================
   PRIMARY RESULT DATA
========================================================= */

$results = (
    isset($checklist['results'])
    && is_array($checklist['results'])
)
    ? $checklist['results']
    : [];

$floorChecklist = (
    isset($checklist['floor_checklist'])
    && is_array($checklist['floor_checklist'])
)
    ? $checklist['floor_checklist']
    : [];

$sectionRemarks = (
    isset($checklist['section_remarks'])
    && is_array($checklist['section_remarks'])
)
    ? $checklist['section_remarks']
    : [];


/* =========================================================
   MERGE DATABASE EVIDENCE INTO DISPLAY ITEMS
   ---------------------------------------------------------
   No database value is written back to checklist JSON.
========================================================= */

function mergeDatabaseEvidence(
    array $item,
    string $ref,
    array $databaseEvidence
): array {
    $embedded = evidenceFromRow($item);

    if (isset($databaseEvidence[$ref])) {
        foreach (
            ['photo', 'video', 'document']
            as $key
        ) {
            if (
                $embedded[$key] === ''
                && !empty($databaseEvidence[$ref][$key])
            ) {
                $embedded[$key] =
                    (string)$databaseEvidence[$ref][$key];
            }
        }
    }

    $item['_display_evidence'] = $embedded;

    return $item;
}


/* =========================================================
   FLATTEN RESULT ROWS FOR SUMMARY
========================================================= */

$displayRows = [];

foreach ($results as $key => $item) {

    if (!is_array($item)) {
        continue;
    }

    $ref = refFromRow(
        $item,
        is_string($key) ? $key : ''
    );

    if ($ref === '') {
        continue;
    }

    $item = applySavedPriority(
        $item,
        $ref,
        $savedPriorityMap
    );

    $item = mergeDatabaseEvidence(
        $item,
        $ref,
        $databaseEvidence
    );

    $displayRows[] = [
        'ref' => $ref,
        'item' => $item,
        'section' => checkpointAreaFromRow(
            $item,
            $ref,
            $serviceCode,
            $isEscalator
        )
    ];
}


/* =========================================================
   FLOOR-WISE DISPLAY ROWS
========================================================= */

$floorDisplayRows = [];

foreach (
    $floorChecklist as $floorLabel => $floorItems
) {

    if (!is_array($floorItems)) {
        continue;
    }

    foreach (
        $floorItems as $floorKey => $item
    ) {

        if (!is_array($item)) {
            continue;
        }

        $ref = refFromRow(
            $item,
            is_string($floorKey)
                ? $floorKey
                : ''
        );

        if ($ref === '') {
            $ref = 'Floor checkpoint';
        }

        /*
         * Floor rows can have a dynamic key instead of a
         * permanent PAT ID. Preserve the saved floor label
         * exactly as stored.
         */
        $item = applySavedPriority(
            $item,
            $ref,
            $savedPriorityMap
        );

        $item = mergeDatabaseEvidence(
            $item,
            $ref,
            $databaseEvidence
        );

        $floorDisplayRows[] = [
            'floor' => (string)$floorLabel,
            'ref' => $ref,
            'item' => $item,
            'section' => checkpointAreaFromRow(
                $item,
                $ref,
                $serviceCode,
                $isEscalator
            )
        ];
    }
}


/* =========================================================
   SUMMARY COUNTS
========================================================= */

$total = 0;
$pending = 0;
$countC = 0;
$countNC = 0;
$countNA = 0;
$countCA = 0;

foreach ($displayRows as $row) {
    $total++;

    $result = resultText(
        $row['item']['result']
        ?? $row['item']['status']
        ?? ''
    );

    switch ($result) {
        case 'C':
            $countC++;
            break;

        case 'NC':
            $countNC++;
            break;

        case 'NA':
            $countNA++;
            break;

        case 'CA':
            $countCA++;
            break;

        default:
            $pending++;
            break;
    }
}

foreach ($floorDisplayRows as $row) {
    $total++;

    $result = resultText(
        $row['item']['result']
        ?? $row['item']['status']
        ?? ''
    );

    switch ($result) {
        case 'C':
            $countC++;
            break;

        case 'NC':
            $countNC++;
            break;

        case 'NA':
            $countNA++;
            break;

        case 'CA':
            $countCA++;
            break;

        default:
            $pending++;
            break;
    }
}


/* =========================================================
   GENERIC RECURSIVE ROW RENDERER
   ---------------------------------------------------------
   Used for SAT matrices / acceptance blocks when their
   structure contains checkpoint-like rows.
========================================================= */

function renderGenericRows(
    $data,
    array &$databaseEvidence,
    string $prefix = '',
    int $depth = 0,
    array $savedPriorityMap = []
): void {
    if (!is_array($data) || $depth > 12) {
        return;
    }

    foreach ($data as $key => $value) {

        if (!is_array($value)) {
            continue;
        }

        if (isCheckpointRow($value)) {

            $ref = refFromRow(
                $value,
                is_string($key)
                    ? $key
                    : ($prefix !== ''
                        ? $prefix
                        : 'Checkpoint')
            );

            $text = textFromRow(
                $value,
                $ref
            );

            $result = resultText(
                $value['result']
                ?? $value['status']
                ?? $value['acceptance_result']
                ?? ''
            );

            $value = applySavedPriority(
                $value,
                $ref,
                $savedPriorityMap
            );

            $value = mergeDatabaseEvidence(
                $value,
                $ref,
                $databaseEvidence
            );

            $remark = remarkFromRow($value);
            $caRemark = caRemarkFromRow($value);
            $justification =
                technicalJustificationFromRow($value);

            $evidence =
                $value['_display_evidence']
                ?? evidenceFromRow($value);

            ?>
            <div class="generic-checkpoint">
                <div class="generic-main">
                    <div class="generic-ref">
                        <?= h($ref) ?>
                    </div>

                    <div class="generic-priority">
                        <?= h(priorityFromRow($value) ?: '—') ?>
                    </div>

                    <div class="generic-name">
                        <?= h($text) ?>
                    </div>

                    <div class="generic-result">
                        <span class="result-badge <?= h(resultClass($result)) ?>">
                            <?= h($result) ?>
                        </span>
                    </div>
                </div>

                <?php if ($remark !== ''): ?>
                    <div class="detail-line">
                        <strong>Engineering Remark:</strong>
                        <?= nl2br(h($remark)) ?>
                    </div>
                <?php endif; ?>

                <?php if ($caRemark !== ''): ?>
                    <div class="detail-line">
                        <strong>Conditional Acceptance Remark / Condition:</strong>
                        <?= nl2br(h($caRemark)) ?>
                    </div>
                <?php endif; ?>

                <?php if ($justification !== ''): ?>
                    <div class="detail-line">
                        <strong>Technical Justification:</strong>
                        <?= nl2br(h($justification)) ?>
                    </div>
                <?php endif; ?>

                <?php
                renderEvidenceBlock($evidence);
                ?>
            </div>
            <?php

            continue;
        }

        renderGenericRows(
            $value,
            $databaseEvidence,
            $prefix,
            $depth + 1,
            $savedPriorityMap
        );
    }
}

function renderEvidenceBlock(array $evidence): void
{
    $hasEvidence =
        !empty($evidence['photo'])
        || !empty($evidence['video'])
        || !empty($evidence['document']);

    if (!$hasEvidence) {
        return;
    }

    ?>
    <div class="evidence-row">

        <span class="evidence-title">
            Evidence:
        </span>

        <?php if (!empty($evidence['photo'])): ?>
            <a
                class="evidence-link"
                href="<?= h(evidenceUrl((string)$evidence['photo'])) ?>"
                target="_blank"
                rel="noopener"
            >
                <i class="bi bi-image"></i>
                View Photo
            </a>
        <?php endif; ?>

        <?php if (!empty($evidence['video'])): ?>
            <a
                class="evidence-link"
                href="<?= h(evidenceUrl((string)$evidence['video'])) ?>"
                target="_blank"
                rel="noopener"
            >
                <i class="bi bi-camera-video"></i>
                View Video
            </a>
        <?php endif; ?>

        <?php if (!empty($evidence['document'])): ?>
            <a
                class="evidence-link"
                href="<?= h(evidenceUrl((string)$evidence['document'])) ?>"
                target="_blank"
                rel="noopener"
            >
                <i class="bi bi-file-earmark"></i>
                View Document
            </a>
        <?php endif; ?>

    </div>
    <?php
}


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-inspector.php');

?>

<style>
.history-view-page {
    padding: 24px;
    background: #f4f6f9;
    min-height: calc(100vh - 70px);
}

.history-view-shell {
    max-width: 1500px;
    margin: 0 auto;
}

.top-actions {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 16px;
}

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    border: 1px solid #d8dee7;
    background: #fff;
    color: #26364d;
    border-radius: 8px;
    padding: 9px 13px;
    font-size: 13px;
    font-weight: 600;
}

.back-btn:hover {
    background: #f7f9fb;
    color: #172033;
}

.readonly-label {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 12px;
    border-radius: 18px;
    background: #e9f5ff;
    color: #0d5d91;
    font-size: 12px;
    font-weight: 700;
}

.page-card {
    background: #fff;
    border: 1px solid #e1e6ed;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
    overflow: hidden;
    margin-bottom: 18px;
}

.page-card-header {
    padding: 21px 24px;
    border-bottom: 1px solid #e9edf2;
}

.page-card-header h2,
.page-card-header h3 {
    margin: 0;
    color: #172033;
    font-weight: 700;
}

.page-card-header p {
    margin: 6px 0 0;
    color: #6c757d;
    font-size: 13px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0;
}

.info-item {
    padding: 15px 18px;
    border-right: 1px solid #edf0f4;
    border-bottom: 1px solid #edf0f4;
}

.info-item:nth-child(4n) {
    border-right: 0;
}

.info-label {
    color: #7a8491;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .03em;
}

.info-value {
    margin-top: 4px;
    color: #172033;
    font-size: 14px;
    font-weight: 600;
    word-break: break-word;
}

.status-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 9px;
    padding: 16px 20px;
    background: #fafbfd;
    border-top: 1px solid #edf0f4;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border-radius: 18px;
    background: #eef1f5;
    color: #4d5865;
    font-size: 11px;
    font-weight: 700;
}

.status-pill.success {
    background: #dff5e8;
    color: #0c6b35;
}

.status-pill.warning {
    background: #fff2c7;
    color: #765300;
}

.status-pill.info {
    background: #e5f0ff;
    color: #0d5ec4;
}

.status-pill.danger {
    background: #fde3e5;
    color: #a61e2a;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(6, minmax(0, 1fr));
    gap: 12px;
    padding: 18px 20px;
}

.summary-box {
    border: 1px solid #e4e9ef;
    border-radius: 10px;
    padding: 13px 14px;
    background: #fff;
}

.summary-box .summary-label {
    color: #6d7783;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
}

.summary-box .summary-value {
    margin-top: 4px;
    color: #172033;
    font-size: 23px;
    font-weight: 700;
}

.summary-box.c {
    border-left: 4px solid #198754;
}

.summary-box.nc {
    border-left: 4px solid #dc3545;
}

.summary-box.na {
    border-left: 4px solid #6c757d;
}

.summary-box.ca {
    border-left: 4px solid #fd7e14;
}

.summary-box.pending {
    border-left: 4px solid #ffc107;
}

.checklist-body {
    padding: 20px;
}

.section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 13px 15px;
    margin: 20px 0 0;
    border: 1px solid #dfe5ec;
    border-bottom: 0;
    border-radius: 10px 10px 0 0;
    background: #f6f8fb;
}

.section-title:first-child {
    margin-top: 0;
}

.section-title strong {
    color: #26364d;
    font-size: 14px;
}

.section-remark {
    padding: 11px 15px;
    border: 1px solid #dfe5ec;
    border-top: 0;
    background: #fbfcfd;
    color: #56616e;
    font-size: 12px;
}

.checkpoint-table-wrap {
    overflow-x: auto;
    border: 1px solid #dfe5ec;
    border-radius: 0 0 10px 10px;
}

.checkpoint-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
}

.checkpoint-table th {
    background: #fff;
    color: #53606f;
    padding: 11px 12px;
    border-bottom: 1px solid #e3e7ed;
    font-size: 11px;
    text-align: left;
    white-space: nowrap;
}

.checkpoint-table td {
    padding: 12px;
    border-bottom: 1px solid #edf0f4;
    color: #293444;
    font-size: 12px;
    vertical-align: top;
}

.checkpoint-table tr:last-child td {
    border-bottom: 0;
}

.ref-cell {
    width: 120px;
    font-weight: 700;
    color: #172033;
    white-space: nowrap;
}

.priority-cell {
    width: 80px;
    font-weight: 800;
    color: #26364d;
    text-align: center;
    white-space: nowrap;
}

.area-cell {
    min-width: 260px;
    color: #455264;
    font-size: 11px;
    font-weight: 700;
    line-height: 1.4;
}

.result-cell {
    width: 85px;
    white-space: nowrap;
}

.result-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 40px;
    padding: 5px 9px;
    border-radius: 13px;
    font-size: 10px;
    font-weight: 800;
}

.result-c {
    background: #d9f4e5;
    color: #0c6b35;
}

.result-nc {
    background: #fde1e4;
    color: #a61e2a;
}

.result-na {
    background: #e9ecef;
    color: #59636f;
}

.result-ca {
    background: #fff0d7;
    color: #a45500;
}

.result-other {
    background: #eef1f5;
    color: #56616e;
}

.detail-block {
    margin-top: 7px;
    color: #586472;
    line-height: 1.5;
}

.detail-block strong {
    color: #303c4b;
}

.evidence-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    margin-top: 9px;
}

.evidence-title {
    color: #6b7580;
    font-size: 10px;
    font-weight: 700;
}

.evidence-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    text-decoration: none;
    padding: 5px 8px;
    border-radius: 6px;
    background: #eef5ff;
    color: #0d5ec4;
    font-size: 10px;
    font-weight: 700;
}

.evidence-link:hover {
    background: #e2efff;
}

.floor-title {
    margin-top: 20px;
    padding: 12px 15px;
    background: #f6f8fb;
    border: 1px solid #dfe5ec;
    border-bottom: 0;
    border-radius: 10px 10px 0 0;
    color: #26364d;
    font-size: 14px;
    font-weight: 700;
}

.generic-checkpoint {
    border: 1px solid #e1e6ed;
    border-radius: 10px;
    padding: 13px 15px;
    margin-top: 10px;
    background: #fff;
}

.generic-main {
    display: grid;
    grid-template-columns: 130px 80px 1fr 80px;
    gap: 12px;
    align-items: start;
}

.generic-ref {
    color: #172033;
    font-size: 11px;
    font-weight: 800;
}

.generic-name {
    color: #293444;
    font-size: 12px;
}

.generic-result {
    text-align: right;
}

.detail-line {
    margin-top: 8px;
    color: #586472;
    font-size: 11px;
    line-height: 1.5;
}

.detail-line strong {
    color: #303c4b;
}


.approval-card {
    padding: 20px;
}

.approval-box {
    border: 1px solid #e1e6ed;
    border-radius: 10px;
    padding: 14px;
    background: #fafbfd;
}

.approval-box + .approval-box {
    margin-top: 10px;
}

.approval-title {
    color: #26364d;
    font-size: 12px;
    font-weight: 800;
}

.approval-value {
    margin-top: 5px;
    color: #586472;
    font-size: 12px;
}

.empty-state {
    padding: 45px 20px;
    text-align: center;
    color: #6c757d;
}

.empty-state i {
    display: block;
    font-size: 38px;
    margin-bottom: 9px;
    color: #adb5bd;
}

.history-error {
    max-width: 850px;
    margin: 50px auto;
    padding: 25px;
    border: 1px solid #f1b7bd;
    border-radius: 12px;
    background: #fff5f6;
    color: #842029;
    font-family: Arial, sans-serif;
}

.history-error h3 {
    margin-top: 0;
}

@media (max-width: 1100px) {
    .info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .info-item:nth-child(4n) {
        border-right: 1px solid #edf0f4;
    }

    .info-item:nth-child(2n) {
        border-right: 0;
    }

    .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 700px) {
    .history-view-page {
        padding: 12px;
    }

    .top-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .info-grid {
        grid-template-columns: 1fr;
    }

    .info-item,
    .info-item:nth-child(2n),
    .info-item:nth-child(4n) {
        border-right: 0;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .generic-main {
        grid-template-columns: 1fr;
    }

    .generic-result {
        text-align: left;
    }
}
</style>

<div class="content">

<div class="history-view-page">

<div class="history-view-shell">

    <!-- =====================================================
         TOP ACTIONS
    ====================================================== -->

    <div class="top-actions">

        <a
            href="history.php"
            class="back-btn"
        >
            <i class="bi bi-arrow-left"></i>
            Back to Inspection History
        </a>

        <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">

            <?php if ($directChecklistUrl !== ''): ?>
                <a
                    href="<?= h($directChecklistUrl) ?>"
                    class="back-btn"
                    style="background:#eef5ff;color:#0d5ec4;border-color:#cfe2ff;"
                >
                    <i class="bi bi-clipboard2-check"></i>
                    Open <?= h($serviceCode) ?> Checklist
                </a>
            <?php endif; ?>

            <div class="readonly-label">
                <i class="bi bi-lock"></i>
                Historical Record — View Only
            </div>

        </div>

    </div>


    <!-- =====================================================
         INSPECTION HEADER
    ====================================================== -->

    <div class="page-card">

        <div class="page-card-header">

            <h2>
                <i class="bi bi-clipboard2-check me-2"></i>
                <?= h($displayChecklistTitle) ?>
            </h2>

            <p>
                <?= h($serviceCode ?: 'Inspection') ?>
                —
                <?= h($serviceName) ?>
                —
                <?= h($checklistCode ?: 'Checklist') ?>
                <?= $revision !== '' ? ' / ' . h($revision) : '' ?>
            </p>

        </div>


        <div class="info-grid">

            <div class="info-item">
                <div class="info-label">Asset ID</div>
                <div class="info-value">
                    <?= h($job['display_asset_id'] ?: ('#' . $actualAssetId)) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Asset Name</div>
                <div class="info-value">
                    <?= h($job['asset_name'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Asset Type</div>
                <div class="info-value">
                    <?= h($job['asset_type'] ?: $assetFamily) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Category</div>
                <div class="info-value">
                    <?= h($job['category'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value">
                    <?= h($job['location'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Owner</div>
                <div class="info-value">
                    <?= h($job['owner_name'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Request No.</div>
                <div class="info-value">
                    <?= h($job['request_id'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">PSGA No.</div>
                <div class="info-value">
                    <?= h($job['psga_no'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">SAF No.</div>
                <div class="info-value">
                    <?= h($job['saf_no'] ?: '—') ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Service</div>
                <div class="info-value">
                    <?= h($serviceCode ?: '—') ?>
                    <?php if ($serviceName !== ''): ?>
                        — <?= h($serviceName) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Inspection Date</div>
                <div class="info-value">
                    <?= formatIST(
                        $job['proposed_inspection_date'] ?? ''
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Assignment</div>
                <div class="info-value">
                    <?= h(
                        ucwords(
                            str_replace(
                                '_',
                                ' ',
                                (string)(
                                    $job['assignment_status']
                                    ?? '—'
                                )
                            )
                        )
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Assigned At</div>
                <div class="info-value">
                    <?= formatIST(
                        $job['assigned_at'] ?? ''
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Started At</div>
                <div class="info-value">
                    <?= formatIST(
                        $job['started_at'] ?? ''
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Submitted At</div>
                <div class="info-value">
                    <?= formatIST(
                        $job['submitted_at'] ?? ''
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Completed At</div>
                <div class="info-value">
                    <?= formatIST(
                        $job['completed_at'] ?? ''
                    ) ?>
                </div>
            </div>

        </div>


        <div class="status-strip">

            <?php if ($finalStatus !== ''): ?>
                <span class="status-pill success">
                    <i class="bi bi-check2-circle"></i>
                    Checklist: <?= h($finalStatus) ?>
                </span>
            <?php endif; ?>

            <span class="status-pill info">
                <i class="bi bi-database-check"></i>
                Job: <?= h(
                    ucwords(
                        str_replace(
                            '_',
                            ' ',
                            (string)(
                                $job['job_status']
                                ?? '—'
                            )
                        )
                    )
                ) ?>
            </span>

            <?php if (!empty($job['certificate_status'])): ?>
                <span class="status-pill">
                    <i class="bi bi-award"></i>
                    Certificate:
                    <?= h($job['certificate_status']) ?>
                </span>
            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         CHECKLIST SUMMARY
    ====================================================== -->

    <div class="page-card">

        <div class="page-card-header">
            <h3>
                <i class="bi bi-bar-chart-line me-2"></i>
                Checklist Summary
            </h3>
            <p>
                Saved result counts for this historical asset inspection.
            </p>
        </div>

        <div class="summary-grid">

            <div class="summary-box">
                <div class="summary-label">Total</div>
                <div class="summary-value">
                    <?= (int)$total ?>
                </div>
            </div>

            <div class="summary-box pending">
                <div class="summary-label">Pending</div>
                <div class="summary-value">
                    <?= (int)$pending ?>
                </div>
            </div>

            <div class="summary-box c">
                <div class="summary-label">C</div>
                <div class="summary-value">
                    <?= (int)$countC ?>
                </div>
            </div>

            <div class="summary-box nc">
                <div class="summary-label">NC</div>
                <div class="summary-value">
                    <?= (int)$countNC ?>
                </div>
            </div>

            <div class="summary-box na">
                <div class="summary-label">NA</div>
                <div class="summary-value">
                    <?= (int)$countNA ?>
                </div>
            </div>

            <div class="summary-box ca">
                <div class="summary-label">CA</div>
                <div class="summary-value">
                    <?= (int)$countCA ?>
                </div>
            </div>

        </div>

    </div>


    <!-- =====================================================
         FIXED CHECKLIST RESULTS
    ====================================================== -->

    <div class="page-card">

        <div class="page-card-header">
            <h3>
                <i class="bi bi-list-check me-2"></i>
                Saved Checklist Results
            </h3>

            <p>
                Original inspection results are displayed exactly from
                the saved inspection record. This page is read-only.
            </p>
        </div>


        <div class="checklist-body">

            <?php if (empty($displayRows)): ?>

                <div class="empty-state">
                    <i class="bi bi-clipboard-x"></i>
                    <strong>No fixed checkpoint results found.</strong>
                    <div class="mt-1">
                        The historical record does not contain saved
                        <code>results[]</code> entries.
                    </div>
                </div>

            <?php else: ?>

                <div class="checkpoint-table-wrap">

                    <table class="checkpoint-table">

                        <thead>
                            <tr>
                                <th>Checkpoint</th>
                                <th>Priority</th>
                                <th>Checkpoint Area</th>
                                <th>Checkpoint Name / Description</th>
                                <th>Result</th>
                                <th>Remarks / Evidence</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($displayRows as $row): ?>

                            <?php
                            $item = $row['item'];

                            $result = resultText(
                                $item['result']
                                ?? $item['status']
                                ?? ''
                            );

                            $remark = remarkFromRow($item);
                            $caRemark = caRemarkFromRow($item);

                            $justification =
                                technicalJustificationFromRow($item);

                            $evidence =
                                $item['_display_evidence']
                                ?? evidenceFromRow($item);

                            $checkpointText =
                                textFromRow(
                                    $item,
                                    $row['ref']
                                );
                            ?>

                            <tr>

                                <td class="ref-cell">
                                    <?= h($row['ref']) ?>
                                </td>

                                <td class="priority-cell">
                                    <?= h(priorityFromRow($item) ?: '—') ?>
                                </td>

                                <td class="area-cell">
                                    <?= h($row['section'] !== '' ? $row['section'] : '—') ?>
                                </td>

                                <td>
                                    <?= nl2br(h($checkpointText)) ?>
                                </td>

                                <td class="result-cell">
                                    <span class="result-badge <?= h(resultClass($result)) ?>">
                                        <?= h($result) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if ($remark !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Engineering Remark:
                                            </strong>
                                            <?= nl2br(h($remark)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($caRemark !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Conditional Acceptance Remark / Condition:
                                            </strong>
                                            <?= nl2br(h($caRemark)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($justification !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Technical Justification:
                                            </strong>
                                            <?= nl2br(h($justification)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                    renderEvidenceBlock(
                                        $evidence
                                    );
                                    ?>

                                    <?php
                                    if (
                                        $remark === ''
                                        && $caRemark === ''
                                        && $justification === ''
                                        && empty($evidence['photo'])
                                        && empty($evidence['video'])
                                        && empty($evidence['document'])
                                    ):
                                    ?>
                                        <span class="meta">
                                            —
                                        </span>
                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 FLOOR-WISE PAT CHECKLIST
            ================================================== -->

            <?php if (!empty($floorDisplayRows)): ?>

                <div class="floor-title">
                    <i class="bi bi-layers me-2"></i>
                    Floor-wise Checklist
                </div>

                <div class="checkpoint-table-wrap">

                    <table class="checkpoint-table">

                        <thead>
                            <tr>
                                <th>Floor</th>
                                <th>Checkpoint</th>
                                <th>Priority</th>
                                <th>Checkpoint Area</th>
                                <th>Checkpoint Name / Description</th>
                                <th>Result</th>
                                <th>Remarks / Evidence</th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php foreach ($floorDisplayRows as $row): ?>

                            <?php
                            $item = $row['item'];

                            $result = resultText(
                                $item['result']
                                ?? $item['status']
                                ?? ''
                            );

                            $remark = remarkFromRow($item);
                            $caRemark = caRemarkFromRow($item);

                            $justification =
                                technicalJustificationFromRow($item);

                            $evidence =
                                $item['_display_evidence']
                                ?? evidenceFromRow($item);

                            $checkpointText =
                                textFromRow(
                                    $item,
                                    $row['ref']
                                );
                            ?>

                            <tr>

                                <td class="ref-cell">
                                    <?= h($row['floor']) ?>
                                </td>

                                <td class="ref-cell">
                                    <?= h($row['ref']) ?>
                                </td>

                                <td class="priority-cell">
                                    <?= h(priorityFromRow($item) ?: '—') ?>
                                </td>

                                <td class="area-cell">
                                    <?= h($row['section'] !== '' ? $row['section'] : '—') ?>
                                </td>

                                <td>
                                    <?= nl2br(h($checkpointText)) ?>
                                </td>

                                <td class="result-cell">
                                    <span class="result-badge <?= h(resultClass($result)) ?>">
                                        <?= h($result) ?>
                                    </span>
                                </td>

                                <td>

                                    <?php if ($remark !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Engineering Remark:
                                            </strong>
                                            <?= nl2br(h($remark)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($caRemark !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Conditional Acceptance Remark / Condition:
                                            </strong>
                                            <?= nl2br(h($caRemark)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($justification !== ''): ?>
                                        <div class="detail-block">
                                            <strong>
                                                Technical Justification:
                                            </strong>
                                            <?= nl2br(h($justification)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php
                                    renderEvidenceBlock(
                                        $evidence
                                    );
                                    ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


            <!-- =================================================
                 SECTION REMARKS
            ================================================== -->

            <?php if (!empty($sectionRemarks)): ?>

                <div class="section-title">
                    <strong>
                        <i class="bi bi-chat-left-text me-2"></i>
                        Section Remarks
                    </strong>
                </div>

                <div class="section-remark">

                    <?php foreach ($sectionRemarks as $section => $remark): ?>

                        <?php if (is_array($remark)): ?>
                            <div class="detail-block">
                                <strong>
                                    <?= h((string)$section) ?>:
                                </strong>
                                <?= nl2br(
                                    h(
                                        json_encode(
                                            $remark,
                                            JSON_UNESCAPED_UNICODE
                                        )
                                    )
                                ) ?>
                            </div>
                        <?php elseif (trim((string)$remark) !== ''): ?>
                            <div class="detail-block">
                                <strong>
                                    <?= h((string)$section) ?>:
                                </strong>
                                <?= nl2br(h((string)$remark)) ?>
                            </div>
                        <?php endif; ?>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         SAT MATRIX / OTHER SAVED EXECUTION DATA
    ====================================================== -->

    <?php
    $specialBlocks = [];

    foreach (
        [
            'matrix' => 'SAT Matrix / Measurement Records',
            'matrices' => 'SAT Matrix / Measurement Records',
            'acceptance' => 'Acceptance / Functional Test Records',
            'acceptance_result' => 'Acceptance / Functional Test Records',
            'signatures' => 'Inspection Signatures'
        ] as $specialKey => $specialTitle
    ) {
        if (
            isset($checklist[$specialKey])
            && is_array($checklist[$specialKey])
            && !empty($checklist[$specialKey])
        ) {
            $specialBlocks[$specialKey] = [
                'title' => $specialTitle,
                'data' => $checklist[$specialKey]
            ];
        }
    }
    ?>

    <?php foreach ($specialBlocks as $special): ?>

        <div class="page-card">

            <div class="page-card-header">
                <h3>
                    <i class="bi bi-grid-3x3-gap me-2"></i>
                    <?= h($special['title']) ?>
                </h3>

                <p>
                    Saved execution data for this historical inspection.
                </p>
            </div>

            <div class="checklist-body">

                <?php
                renderGenericRows(
                    $special['data'],
                    $databaseEvidence,
                    '',
                    0,
                    $savedPriorityMap
                );
                ?>

            </div>

        </div>

    <?php endforeach; ?>
    <!-- =====================================================
         OWNER APPROVAL
    ====================================================== -->

    <?php if (!empty($ownerApproval)): ?>

        <div class="page-card">

            <div class="page-card-header">
                <h3>
                    <i class="bi bi-person-check me-2"></i>
                    Owner Approval Information
                </h3>

                <p>
                    Saved Owner decision for this historical inspection.
                </p>
            </div>

            <div class="approval-card">

                <?php foreach (
                    $ownerApproval as $key => $value
                ): ?>

                    <?php
                    if (is_array($value)) {
                        $displayValue = json_encode(
                            $value,
                            JSON_UNESCAPED_UNICODE
                            | JSON_UNESCAPED_SLASHES
                        );
                    } else {
                        $displayValue = (string)$value;
                    }
                    ?>

                    <?php if (trim($displayValue) !== ''): ?>

                        <div class="approval-box">

                            <div class="approval-title">
                                <?= h(
                                    ucwords(
                                        str_replace(
                                            '_',
                                            ' ',
                                            (string)$key
                                        )
                                    )
                                ) ?>
                            </div>

                            <div class="approval-value">
                                <?= nl2br(
                                    h($displayValue)
                                ) ?>
                            </div>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         FINAL REMARKS / OWNER REMARK
    ====================================================== -->

    <?php if (
        trim(
            (string)(
                $job['owner_remark']
                ?? ''
            )
        ) !== ''
        ||
        trim(
            (string)(
                $job['assignment_remarks']
                ?? ''
            )
        ) !== ''
    ): ?>

        <div class="page-card">

            <div class="page-card-header">
                <h3>
                    <i class="bi bi-chat-square-text me-2"></i>
                    Inspection Remarks
                </h3>
            </div>

            <div class="approval-card">

                <?php if (
                    trim(
                        (string)(
                            $job['assignment_remarks']
                            ?? ''
                        )
                    ) !== ''
                ): ?>

                    <div class="approval-box">

                        <div class="approval-title">
                            Inspector Assignment Remark
                        </div>

                        <div class="approval-value">
                            <?= nl2br(
                                h(
                                    (string)(
                                        $job['assignment_remarks']
                                        ?? ''
                                    )
                                )
                            ) ?>
                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    trim(
                        (string)(
                            $job['owner_remark']
                            ?? ''
                        )
                    ) !== ''
                ): ?>

                    <div class="approval-box">

                        <div class="approval-title">
                            Owner Remark
                        </div>

                        <div class="approval-value">
                            <?= nl2br(
                                h(
                                    (string)(
                                        $job['owner_remark']
                                        ?? ''
                                    )
                                )
                            ) ?>
                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         FOOTER INFORMATION
    ====================================================== -->

    <div class="page-card">

        <div class="page-card-header">

            <p style="margin:0;">
                Historical checklist record • Job ID:
                <strong><?= (int)$jobId ?></strong>
                • Assignment ID:
                <strong><?= (int)($job['assignment_id'] ?? 0) ?></strong>
                • All displayed times are IST.
            </p>

        </div>

    </div>

</div>

</div>

</div>

<?php
/*
 * Keep this page strictly read-only.
 * No POST handler, no checklist update and no redirect to
 * inspections.php is performed after the record is loaded.
 */
?>
