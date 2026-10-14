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
       - Photograph mandatory for NC
       - Video optional
       - NC requires Engineering Remark
       - CA requires Conditional Acceptance Remark / Condition
       - OWNER SEES NC AND CA CHECKPOINTS
       - CA is Conditional Acceptance; no Deferred/D result
       - RECHECK STATUS USES CONTENT-BASED SPACE
       - ALL DISPLAYED TIMES ARE IST

   PRESERVED:
       - Owner authentication
       - Owner/job authorization
       - SAF information
       - Inspector information
       - Technician information (legacy, if present)
       - Checklist summary
       - Recheck status/results
       - Evidence links
       - Section remarks
       - Owner approval information
       - Report request workflow

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
        $dt = new DateTime(
            (string)$value,
            new DateTimeZone('Asia/Kolkata')
        );

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return h(
            $dt->format($format)
        );

    } catch (Exception $e) {
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


function getRecheckEvidence(
    array $item,
    string $ref,
    array $databaseEvidence
): array
{
    $evidence = [
        'photo' => '',
        'video' => '',
        'document' => ''
    ];

    foreach (['photo', 'video', 'document'] as $key) {
        if (!empty($item[$key])) {
            $evidence[$key] = (string)$item[$key];
        }
    }

    if (isset($item['evidence']) && is_array($item['evidence'])) {
        foreach (['photo', 'video', 'document'] as $key) {
            if (
                $evidence[$key] === ''
                && !empty($item['evidence'][$key])
            ) {
                $evidence[$key] = (string)$item['evidence'][$key];
            }
        }
    }

    /*
     * Recheck evidence can be stored under the same ref_code
     * in saf_checklist_evidence. Use it only as a fallback;
     * original inspection evidence is never overwritten.
     */
    if (isset($databaseEvidence[$ref])) {
        foreach (['photo', 'video', 'document'] as $key) {
            if (
                $evidence[$key] === ''
                && !empty($databaseEvidence[$ref][$key])
            ) {
                $evidence[$key] = (string)$databaseEvidence[$ref][$key];
            }
        }
    }

    return $evidence;
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
        mj.created_at,
        mj.updated_at,

        mj.checklist,

        mj.recheck_date,
        mj.recheck_status,
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

$sectionRemarks = (
    isset($checklist['section_remarks'])
    &&
    is_array($checklist['section_remarks'])
)
    ? $checklist['section_remarks']
    : [];

$recheckResults = (
    isset($checklist['recheck_results'])
    &&
    is_array($checklist['recheck_results'])
)
    ? $checklist['recheck_results']
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
   CHECKLIST ORDER
   ---------------------------------------------------------
   PAT keeps the permanent PAT-001 -> PAT-131 order.
   SAT uses its saved SAT-001 -> SAT-031 order and preserves
   any additional/matrix records after the fixed master.
========================================================= */

$patRefs = [];
$satRefs = [];
$sciRefs = [];

for ($i = 1; $i <= 131; $i++) {
    $patRefs[] = 'PAT-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
}

for ($i = 1; $i <= 31; $i++) {
    $satRefs[] = 'SAT-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
}

for ($i = 1; $i <= 66; $i++) {
    $sciRefs[] = 'SCI-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT);
}

$masterRefs = $isSci
    ? $sciRefs
    : ($isSat ? $satRefs : $patRefs);

$orderedResults = [];

foreach ($masterRefs as $ref) {
    if (isset($results[$ref])) {
        $orderedResults[$ref] = $results[$ref];
    }
}

/*
 * Preserve any legacy/additional saved records after the current
 * permanent master. Nothing is silently deleted.
 */
foreach ($results as $ref => $item) {
    if (!array_key_exists($ref, $orderedResults)) {
        $orderedResults[$ref] = $item;
    }
}

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

$masterCount = $isSci ? 66 : ($isSat ? 31 : 131);

$missingCount = max(
    0,
    $masterCount - $totalCount
);


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
   RECHECK — ONE TIME ONLY
========================================================= */

$recheckStatus = strtolower(
    trim(
        (string)(
            $job['recheck_status']
            ?? ''
        )
    )
);

$recheckDateText = '—';

if (!empty($job['recheck_date'])) {
    $recheckDateText = formatIstDate(
        $job['recheck_date'],
        'd / m / Y'
    );
}

$ownerRecheckRemark = trim(
    (string)(
        $job['owner_remark']
        ?? ''
    )
);

$recheckOkCount = 0;
$recheckNcCount = 0;
$recheckCaCount = 0;
$recheckCompletedCount = 0;

/*
 * Recheck is permitted exactly once. The save handler stores the
 * single recheck result either as an object or, for compatibility
 * with older records, as a one-element array.
 */
foreach ($recheckResults as $ref => $item) {

    if (!is_array($item)) {
        continue;
    }

    /* Legacy/multi-cycle compatibility: use only the final entry. */
    if (isset($item[0]) && is_array($item[0])) {
        $item = $item[count($item) - 1];
    }

    $originalItem = $results[$ref] ?? null;

    if (!is_array($originalItem)) {
        continue;
    }

    $originalResult = strtoupper(
        trim(
            (string)(
                $originalItem['result'] ?? ''
            )
        )
    );

    if ($originalResult !== 'NC') {
        continue;
    }

    $rr = strtoupper(
        trim(
            (string)(
                $item['result'] ?? ''
            )
        )
    );

    if (in_array($rr, ['C', 'OK'], true)) {
        $recheckOkCount++;
        $recheckCompletedCount++;
    } elseif ($rr === 'NC') {
        $recheckNcCount++;
        $recheckCompletedCount++;
    } elseif ($rr === 'CA') {
        $recheckCaCount++;
        $recheckCompletedCount++;
    }
}

$hasRecheck =
    !empty($job['recheck_date'])
    ||
    !empty($recheckResults);

/* =========================================================
   FLOOR-WISE RESULTS
   ---------------------------------------------------------
   D2 contains seven landing-door checks per floor.
   The Owner view shows only NC items, including floor-wise NCs.
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

$ownerNcTotal = count($ownerNcItems) + count($floorNcItems);

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
                : []
        ];
    }
}

$ownerCaTotal = count($ownerCaItems) + count($floorCaItems);

/*
 * Recheck-eligible CA items:
 * Only Owner-rejected CA checkpoints enter recheck.
 * Owner-approved CA checkpoints stay CA and go to the final report.
 */
$ownerRejectedCaItems = [];

foreach ($ownerCaItems as $ref => $item) {
    $decision = (
        isset($item['owner_ca_decision'])
        && is_array($item['owner_ca_decision'])
    )
        ? $item['owner_ca_decision']
        : [];

    if (
        strtolower(trim((string)($decision['status'] ?? '')))
        === 'rejected'
    ) {
        $ownerRejectedCaItems[$ref] = $item;
    }
}

$floorRejectedCaItems = [];

foreach ($floorCaItems as $item) {
    $decision = (
        isset($item['owner_ca_decision'])
        && is_array($item['owner_ca_decision'])
    )
        ? $item['owner_ca_decision']
        : [];

    if (
        strtolower(trim((string)($decision['status'] ?? '')))
        === 'rejected'
    ) {
        $floorRejectedCaItems[] = $item;
    }
}

$recheckEligibleTotal =
    $ownerNcTotal
    + count($ownerRejectedCaItems)
    + count($floorRejectedCaItems);

$ownerCaPendingTotal = 0;
$ownerCaApprovedTotal = 0;
$ownerCaRejectedTotal = 0;

foreach ($ownerCaItems as $item) {
    $decisionStatus = strtolower(trim((string)(
        is_array($item['owner_ca_decision'] ?? null)
            ? ($item['owner_ca_decision']['status'] ?? '')
            : ''
    )));

    if ($decisionStatus === 'approved') {
        $ownerCaApprovedTotal++;
    } elseif ($decisionStatus === 'rejected') {
        $ownerCaRejectedTotal++;
    } else {
        $ownerCaPendingTotal++;
    }
}

foreach ($floorCaItems as $item) {
    $decisionStatus = strtolower(trim((string)(
        is_array($item['owner_ca_decision'] ?? null)
            ? ($item['owner_ca_decision']['status'] ?? '')
            : ''
    )));

    if ($decisionStatus === 'approved') {
        $ownerCaApprovedTotal++;
    } elseif ($decisionStatus === 'rejected') {
        $ownerCaRejectedTotal++;
    } else {
        $ownerCaPendingTotal++;
    }
}

$allCaDecisionsFinal =
    $ownerCaTotal === 0
    || $ownerCaPendingTotal === 0;

/*
 * A recheck is needed when:
 * - at least one original NC exists, OR
 * - at least one CA was rejected by the Owner.
 *
 * However, it must remain locked until every CA has a final
 * Owner decision.
 */
$recheckRequiredAfterCaDecision =
    $ownerNcTotal > 0
    || $ownerCaRejectedTotal > 0;

$recheckCanBeScheduled =
    $allCaDecisionsFinal
    && $recheckRequiredAfterCaDecision
    && !in_array(
        $recheckStatus,
        ['scheduled', 'in_progress', 'completed'],
        true
    );

$recheckBlockedByPendingCa =
    $ownerCaPendingTotal > 0;

$ownerApprovalComplete =
    $approvalStatus === 'approved'
    || $job['job_status'] === 'approved';


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
       - no recheck may remain scheduled/in_progress
       - maintenance_jobs.status = closed
       - sat_status = unlocked
       - checklist.pat_status = closed

   SAT Complete:
       - final report must be sent
       - no recheck may remain scheduled/in_progress
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
                            recheck_status,
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

                    $freshRecheckStatus = strtolower(
                        trim(
                            (string)(
                                $freshJob['recheck_status']
                                ?? ''
                            )
                        )
                    );

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

                        /*
                         * A scheduled/in-progress recheck blocks completion.
                         */
                        if (
                            $freshRecheckStatus === 'scheduled'
                            ||
                            $freshRecheckStatus === 'in_progress'
                        ) {
                            throw new RuntimeException(
                                $freshServiceLabel
                                . ' cannot be closed while the recheck is still pending.'
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
    min-width: 760px;
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
   RECHECK STATUS — AUTO / CONTENT BASED SPACE
========================================================= */

.recheck-summary {
    margin: 16px;
    border: 1px solid #cfe2ff;
    border-radius: 10px;
    background: #eef6ff;
    padding: 12px 14px;

    /*
     * Do not stretch the box unnecessarily.
     * It grows according to the content.
     */
    width: fit-content;
    max-width: calc(100% - 32px);

    height: auto;
}

.recheck-summary-head {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 18px;
    flex-wrap: wrap;

    width: max-content;
    max-width: 100%;
}

.recheck-summary-head > div {
    flex: 0 0 auto;
}

.recheck-summary-title {
    color: #0d6efd;
    font-weight: 700;
    text-transform: uppercase;
    font-size: 12px;
    white-space: nowrap;
}

.recheck-summary-date {
    font-size: 18px;
    font-weight: 700;
    color: #172033;
    white-space: nowrap;
}

.recheck-note {
    margin-top: 8px;
    color: #495057;
    font-size: 12px;
    width: fit-content;
    max-width: 100%;
}


/* Recheck result table also sizes the timestamp column
   according to its actual content. */
.recheck-table {
    min-width: 980px;
    width: 100%;
}

.recheck-table th,
.recheck-table td {
    white-space: nowrap;
}

.recheck-table th:nth-child(4),
.recheck-table td:nth-child(4) {
    white-space: normal;
    min-width: 180px;
}

.recheck-table th:nth-child(5),
.recheck-table td:nth-child(5) {
    width: 1%;
    white-space: nowrap;
}


/* =========================================================
   OWNER RECHECK INSTRUCTIONS
========================================================= */

.owner-recheck-remark {
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid #cfe2ff;
    border-radius: 8px;
    background: #f8fbff;
}

.owner-recheck-remark-title {
    color: #0d6efd;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 5px;
}

.owner-recheck-remark-text {
    color: #172033;
    font-size: 13px;
    line-height: 1.55;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.recheck-pending-report {
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid #ffe69c;
    border-radius: 8px;
    background: #fff8e1;
    color: #7a5200;
    font-size: 13px;
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

    .recheck-summary {
        width: auto;
        max-width: calc(100% - 20px);
        margin: 10px;
    }

    .recheck-summary-head {
        width: 100%;
        gap: 8px;
    }

    .recheck-summary-title,
    .recheck-summary-date {
        white-space: normal;
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

                    <?= formatIstDate(
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
         OWNER NC-ONLY CHECKLIST
    ====================================================== -->

    <div class="card-box">

        <div class="card-title">

            <i class="bi bi-exclamation-triangle"></i>

            <?= h($isSci ? 'NC Checkpoints — SCI' : ($isSat ? 'NC Checkpoints — SAT' : 'NC Checkpoints — PAT')) ?>

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

                    <thead>

                        <tr>

                            <th style="width:110px;">
                                Checkpoint
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

                        $photo =
                            (string)(
                                $item['photo']
                                ?? ''
                            );

                        $video =
                            (string)(
                                $item['video']
                                ?? ''
                            );

                        $document =
                            (string)(
                                $item['document']
                                ??
                                $item['document_path']
                                ??
                                ''
                            );

                        ?>

                        <tr>

                            <td>

                                <span class="checkpoint-id">

                                    <?= h($ref) ?>

                                </span>

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
                                    $floorPhoto = $floorItem['photo'];
                                    $floorVideo = $floorItem['video'];

                                    if (
                                        isset($floorItem['evidence']['photo'])
                                        && $floorPhoto === ''
                                    ) {
                                        $floorPhoto = (string)$floorItem['evidence']['photo'];
                                    }

                                    if (
                                        isset($floorItem['evidence']['video'])
                                        && $floorVideo === ''
                                    ) {
                                        $floorVideo = (string)$floorItem['evidence']['video'];
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

        <div class="card-box">

            <div class="card-title">

                <i class="bi bi-shield-check"></i>

                Conditional Acceptance (CA)

            </div>

            <div class="table-wrap">

                <table class="checklist-table nc-only-table">

                    <thead>
                        <tr>
                            <th style="width:140px;">Checkpoint</th>
                            <th style="width:110px;">Result</th>
                            <th>Remarks</th>
                            <th style="width:300px;">Evidence</th>
                            <th style="width:230px;">Owner Decision</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($ownerCaItems as $ref => $item): ?>

                        <?php
                        $caRemark = trim((string)($item['remark'] ?? ''));
                        $caJustification = trim((string)(
                            $item['ca_remark']
                            ?? $item['technical_justification']
                            ?? ''
                        ));

                        $caPhoto = (string)($item['photo'] ?? '');
                        $caVideo = (string)($item['video'] ?? '');
                        $caDocument = (string)(
                            $item['document']
                            ?? $item['document_path']
                            ?? ''
                        );
                        ?>

                        <tr>
                            <td>
                                <span class="checkpoint-id">
                                    <?= h($ref) ?>
                                </span>
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

                                    <div class="ca-action-buttons">

                                        <form method="POST"
                                              action="owner-ca-decision.php"
                                              class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                            <input type="hidden" name="owner_action" value="ca_approve">
                                            <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                            <input type="hidden" name="ca_ref" value="<?= h($ref) ?>">
                                            <input type="hidden" name="ca_floor" value="">

                                            <button type="submit"
                                                    class="ca-approve-btn"
                                                    onclick="return confirm('Approve this Conditional Acceptance? This records your permission to accept this checkpoint conditionally.');">
                                                <i class="bi bi-check-circle"></i>
                                                Approve
                                            </button>
                                        </form>

                                        <form method="POST"
                                              action="owner-ca-decision.php"
                                              class="d-inline"
                                              onsubmit="return prepareCaReject(this);">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                            <input type="hidden" name="owner_action" value="ca_reject">
                                            <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                            <input type="hidden" name="ca_ref" value="<?= h($ref) ?>">
                                            <input type="hidden" name="ca_floor" value="">
                                            <input type="hidden" name="ca_reject_reason" value="">
                                            <button type="submit"
                                                    class="ca-reject-btn">
                                                <i class="bi bi-x-circle"></i>
                                                Reject
                                            </button>
                                        </form>

                                    </div>

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
                                    $floorCaPhoto = (string)$floorItem['photo'];
                                    $floorCaVideo = (string)$floorItem['video'];

                                    if (
                                        isset($floorItem['evidence']['photo'])
                                        && $floorCaPhoto === ''
                                    ) {
                                        $floorCaPhoto = (string)$floorItem['evidence']['photo'];
                                    }

                                    if (
                                        isset($floorItem['evidence']['video'])
                                        && $floorCaVideo === ''
                                    ) {
                                        $floorCaVideo = (string)$floorItem['evidence']['video'];
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

                                    <div class="ca-action-buttons">

                                        <form method="POST"
                                              action="owner-ca-decision.php"
                                              class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                            <input type="hidden" name="owner_action" value="ca_approve">
                                            <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                            <input type="hidden" name="ca_ref" value="<?= h($floorItem['checkpoint']) ?>">
                                            <input type="hidden" name="ca_floor" value="<?= h($floorItem['floor']) ?>">

                                            <button type="submit"
                                                    class="ca-approve-btn"
                                                    onclick="return confirm('Approve this Conditional Acceptance? This records your permission to accept this checkpoint conditionally.');">
                                                <i class="bi bi-check-circle"></i>
                                                Approve
                                            </button>
                                        </form>

                                        <form method="POST"
                                              action="owner-ca-decision.php"
                                              class="d-inline"
                                              onsubmit="return prepareCaReject(this);">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
                                            <input type="hidden" name="owner_action" value="ca_reject">
                                            <input type="hidden" name="ca_return" value="<?= h(basename($_SERVER['SCRIPT_NAME'])) ?>">
                                            <input type="hidden" name="ca_ref" value="<?= h($floorItem['checkpoint']) ?>">
                                            <input type="hidden" name="ca_floor" value="<?= h($floorItem['floor']) ?>">
                                            <input type="hidden" name="ca_reject_reason" value="">
                                            <button type="submit"
                                                    class="ca-reject-btn">
                                                <i class="bi bi-x-circle"></i>
                                                Reject
                                            </button>
                                        </form>

                                    </div>

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
         RECHECK SCHEDULING
         -----------------------------------------------------
         Rules:
           1. If CA exists, every CA must have Owner Approve/Reject
              decision before Recheck Schedule is unlocked.
           2. If no CA exists, NC can directly be scheduled.
           3. Recheck is one-time only.
           4. Recheck is required for original NC or Owner-rejected CA.
    ====================================================== -->

    <?php if (
        $recheckCanBeScheduled
        && !$hasRecheck
    ): ?>

        <div class="card-box">

            <div class="card-title">
                <i class="bi bi-calendar2-check"></i>
                Recheck Schedule
            </div>

            <div class="action-panel">

                <div class="action-status requested">

                    <strong>
                        <i class="bi bi-arrow-repeat me-1"></i>
                        Recheck is Ready to Schedule
                    </strong>

                    <div class="action-help">

                        <?php if ($ownerCaRejectedTotal > 0): ?>
                            Owner rejected
                            <strong><?= (int)$ownerCaRejectedTotal ?></strong>
                            Conditional Acceptance checkpoint(s).
                            <?php if ($ownerNcTotal > 0): ?>
                                There are also
                                <strong><?= (int)$ownerNcTotal ?></strong>
                                original NC checkpoint(s).
                            <?php endif; ?>
                            Both will be handled in the single recheck.
                        <?php elseif ($ownerNcTotal > 0): ?>
                            There are
                            <strong><?= (int)$ownerNcTotal ?></strong>
                            original NC checkpoint(s) requiring recheck.
                        <?php endif; ?>

                    </div>

                </div>

                <form
                    method="POST"
                    action="owner-ca-decision.php"
                    onsubmit="return prepareRecheckSchedule(this);"
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
                        value="schedule_recheck"
                    >

                    <input
                        type="hidden"
                        name="recheck_date"
                        value=""
                    >

                    <input
                        type="hidden"
                        name="recheck_remark"
                        value=""
                    >

                    <button
                        type="submit"
                        class="pat-action-btn certificate-request-btn"
                    >
                        <i class="bi bi-calendar2-plus me-1"></i>
                        Schedule Recheck
                    </button>

                </form>

            </div>

        </div>

    <?php elseif (
        $recheckBlockedByPendingCa
        && !$hasRecheck
    ): ?>

        <div class="card-box">

            <div class="card-title">
                <i class="bi bi-lock-fill"></i>
                Recheck Schedule Locked
            </div>

            <div class="action-panel">

                <div class="action-status requested">

                    <strong>
                        <i class="bi bi-shield-lock me-1"></i>
                        CA decision pending
                    </strong>

                    <div class="action-help">

                        <?= (int)$ownerCaPendingTotal ?>
                        Conditional Acceptance checkpoint(s) are still
                        waiting for Owner decision.

                        <br>

                        Please <strong>Approve</strong> or
                        <strong>Reject</strong> every CA checkpoint first.
                        After all CA decisions are final, the Recheck
                        Schedule option will open automatically.

                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         RECHECK STATUS
    ====================================================== -->

    <?php if ($hasRecheck): ?>

        <div class="card-box">

            <div class="card-title">

                <i class="bi bi-arrow-repeat"></i>

                Recheck Status

            </div>


            <div class="recheck-summary">     

                <?php if ($ownerRecheckRemark !== ''): ?>
    
                <?php endif; ?>


                <?php if (
                    $recheckStatus
                    ===
                    'scheduled'
                ): ?>

                    <div class="recheck-note">

                        <i class="bi bi-info-circle me-1"></i>

                        Waiting for Inspector to complete
                        the recheck.

                    </div>

                <?php elseif (
                    $recheckStatus
                    ===
                    'in_progress'
                ): ?>

                    <div class="recheck-note">

                        <i class="bi bi-hourglass-split me-1"></i>

                        The assigned Inspector is currently
                        performing the recheck.

                    </div>

                <?php elseif (
                    $recheckStatus
                    ===
                    'completed'
                ): ?>

                    <div class="recheck-note">

                        <i class="bi bi-check-circle me-1"></i>

                        Recheck completed.

                        <strong>
                            <?= (int)$recheckCompletedCount ?>
                            of
                            <?= (int)$recheckEligibleTotal ?>
                            eligible NC / Owner-rejected CA checkpoints rechecked.
                        </strong>

                        &nbsp;

                        <span class="text-success">
                            <?= (int)$recheckOkCount ?> C
                        </span>

                        &nbsp;

                        <span class="text-danger">
                            <?= (int)$recheckNcCount ?> NC
                        </span>

                        &nbsp;

                        <span class="text-warning">
                            <?= (int)$recheckCaCount ?> CA
                        </span>

                    </div>

                <?php endif; ?>

            </div>


            <?php if (
                $recheckStatus
                ===
                'completed'
                &&
                !empty($recheckResults)
            ): ?>

                <div class="table-wrap">

                    <table
                        class="checklist-table recheck-table"
                    >

                        <thead>

                            <tr>

                                <th>
                                    Checkpoint
                                </th>

                                <th>
                                    Original
                                </th>

                                <th>
                                    Recheck
                                </th>

                                <th>
                                    Remark
                                </th>

                                <th style="min-width:220px;">
                                    Evidence
                                </th>

                                <th>
                                    Rechecked At (IST)
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $recheckResults
                            as $ref => $item
                        ): ?>

                            <?php

                            if (!is_array($item)) {
                                continue;
                            }

                            if (isset($item[0]) && is_array($item[0])) {
                                $item = $item[count($item) - 1];
                            }

                            $originalItem =
                                $results[$ref]
                                ??
                                null;

                            if (
                                !is_array(
                                    $originalItem
                                )
                            ) {
                                continue;
                            }

                            $originalResult =
                                strtoupper(
                                    trim(
                                        (string)(
                                            $originalItem[
                                                'result'
                                            ]
                                            ??
                                            ''
                                        )
                                    )
                                );

                            $ownerDecision = (
                                isset($originalItem['owner_ca_decision'])
                                && is_array($originalItem['owner_ca_decision'])
                            )
                                ? $originalItem['owner_ca_decision']
                                : [];

                            $ownerDecisionStatus = strtolower(trim((string)(
                                $ownerDecision['status'] ?? ''
                            )));

                            $eligibleForRecheck =
                                $originalResult === 'NC'
                                ||
                                (
                                    $originalResult === 'CA'
                                    && $ownerDecisionStatus === 'rejected'
                                );

                            if (!$eligibleForRecheck) {
                                continue;
                            }

                            $newResult =
                                strtoupper(
                                    trim(
                                        (string)(
                                            $item['result']
                                            ??
                                            ''
                                        )
                                    )
                                );

                            $newResultClass =
                                resultClass(
                                    $newResult
                                );

                            $newResultLabel =
                                resultLabel(
                                    $newResult
                                );

                            $recheckRemark =
                                (string)(
                                    $item['remark']
                                    ??
                                    ''
                                );

                            $checkedAt =
                                (string)(
                                    $item['checked_at']
                                    ??
                                    ''
                                );

                            $recheckEvidence =
                                getRecheckEvidence(
                                    $item,
                                    (string)$ref,
                                    $databaseEvidence
                                );

                            $recheckPhoto =
                                $recheckEvidence['photo'];

                            $recheckVideo =
                                $recheckEvidence['video'];

                            $recheckDocument =
                                $recheckEvidence['document'];

                            ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?= h($ref) ?>
                                    </strong>

                                </td>


                                <td>

                                    <span
                                        class="result-badge <?= $originalResult === 'CA' ? 'result-ca' : 'result-nc' ?>"
                                    >
                                        <?= h($originalResult === 'CA' ? 'CA (Owner Rejected)' : 'NC') ?>
                                    </span>

                                </td>


                                <td>

                                    <span
                                        class="result-badge <?= h(
                                            $newResultClass
                                        ) ?>"
                                    >

                                        <?= h(
                                            $newResultLabel
                                        ) ?>

                                    </span>

                                </td>


                                <td class="remark-cell">

                                    <?php if (
                                        trim(
                                            $recheckRemark
                                        )
                                        !==
                                        ''
                                    ): ?>

                                        <?= nl2br(
                                            h(
                                                $recheckRemark
                                            )
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <div class="evidence-links">

                                        <?php if ($recheckPhoto !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($recheckPhoto)) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-image"></i>
                                                Photo
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($recheckVideo !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($recheckVideo)) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-camera-video"></i>
                                                Video
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($recheckDocument !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($recheckDocument)) ?>"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-file-earmark"></i>
                                                Document
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            $recheckPhoto === ''
                                            && $recheckVideo === ''
                                            && $recheckDocument === ''
                                        ): ?>
                                            <span class="empty-evidence">
                                                No evidence uploaded
                                            </span>
                                        <?php endif; ?>

                                    </div>

                                </td>

                                <td>

                                    <?= formatIstDate(
                                        $checkedAt,
                                        'd / m / Y h:i A'
                                    ) ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

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


            <?php elseif (
                $recheckStatus === 'scheduled'
                || $recheckStatus === 'in_progress'
            ): ?>

                <div class="report-status-box report-status-requested">

                    <div class="report-status-title">

                        <i class="bi bi-arrow-repeat me-1"></i>

                        Recheck Pending

                    </div>

                    <div class="report-status-text">

                        <?php if ($recheckStatus === 'scheduled'): ?>

                            A recheck has been scheduled by the Owner.
                            The final report request will be available
                            after the Inspector completes the single recheck.

                        <?php else: ?>

                            The assigned Inspector is currently
                            performing the single permitted recheck.
                            The final report request will be available
                            after submission.

                        <?php endif; ?>

                    </div>

                    <div class="small text-muted mt-2">

                        Recheck Date:

                        <strong>
                            <?= h($recheckDateText) ?>
                        </strong>

                    </div>

                </div>


            <?php elseif (
                !$ownerApprovalComplete
            ): ?>

                <div class="report-status-box report-status-requested">

                    <div class="report-status-title">

                        <i class="bi bi-lock-fill me-1"></i>

                        Report Request Locked

                    </div>

                    <div class="report-status-text">

                        Inspection Report request is locked until
                        <strong>Owner Approval Information</strong>
                        shows <strong>Checklist approval completed.</strong>

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


                    <button
                        type="button"
                        class="report-request-btn"
                        onclick="openReportRequestModal();"
                    >

                        <i class="bi bi-file-earmark-arrow-up me-1"></i>

                        Request Report

                    </button>

                </div>


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

                            <?= formatIstDate(
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

                            <?= formatIstDate(
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


                    <button
                        type="button"
                        class="report-request-btn mt-3"
                        onclick="openReportRequestModal();"
                    >

                        <i class="bi bi-arrow-repeat me-1"></i>

                        Request Report Again

                    </button>

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
                                ? 'SCI can be closed only after the final inspection report has been sent by the Inspector and no recheck remains pending.'
                                : ($isSat
                                    ? 'SAT can be closed only after the final inspection report has been sent by the Inspector and no recheck remains pending.'
                                    : 'PAT can be closed only after the final inspection report has been sent by the Inspector and no recheck remains pending.')
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
                            )
                            &&
                            $recheckStatus !==
                                'scheduled'
                            &&
                            $recheckStatus !==
                                'in_progress';
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
                        >Please prepare and send the final inspection report for this lift.</textarea>

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
function prepareRecheckSchedule(form)
{
    const dateInput =
        form.querySelector('input[name="recheck_date"]');

    if (!dateInput) {
        alert('Unable to prepare the recheck date.');
        return false;
    }

    const selectedDate = window.prompt(
        'Enter the recheck date (YYYY-MM-DD):'
    );

    if (selectedDate === null) {
        return false;
    }

    const trimmedDate = selectedDate.trim();

    if (!/^\d{4}-\d{2}-\d{2}$/.test(trimmedDate)) {
        alert('Please enter the recheck date in YYYY-MM-DD format.');
        return false;
    }

    dateInput.value = trimmedDate;

    return confirm(
        'Schedule the one-time recheck for ' + trimmedDate + '?'
    );
}


function prepareCaReject(form)
{
    const reason = window.prompt(
        'Please enter the reason for rejecting this Conditional Acceptance:'
    );

    if (reason === null) {
        return false;
    }

    const trimmedReason = reason.trim();

    if (trimmedReason === '') {
        alert('Rejection reason is required.');
        return false;
    }

    const reasonInput =
        form.querySelector(
            'input[name="ca_reject_reason"]'
        );

    if (!reasonInput) {
        alert('Unable to prepare the rejection reason.');
        return false;
    }

    reasonInput.value = trimmedReason;

    return confirm(
        'Reject this Conditional Acceptance? Recheck scheduling will remain locked until all CA decisions are final.'
    );
}


function openReportRequestModal()
{
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


<?php include('../includes/footer.php'); ?>