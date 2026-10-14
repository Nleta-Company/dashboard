<?php
/*
|--------------------------------------------------------------------------
| NLETA INSPECTOR — UNIVERSAL REPORT GENERATOR
|--------------------------------------------------------------------------
| File: inspector/generator.php
|
| Supports:
|   Lift / Elevator
|       PAT, SAT, SCI
|   Escalator / Travellator
|       PAT, SAT, SCI
|
| SOURCE OF TRUTH
| ----------------
| The completed Inspector checklist saved in maintenance_jobs.checklist.
|
| The report generator DOES NOT maintain separate checkpoint/priority
| masters.  checkpoint_id, checkpoint_name, priority, section, result,
| remark and technical_justification are read from the saved checklist.
|
| This prevents:
|   Lift PAT   -> being confused with Escalator PAT
|   Lift SAT   -> being confused with Escalator SAT
|   Lift SCI   -> being confused with Escalator SCI
|
| Existing report numbering, approval and PDF storage workflow is retained.
|--------------------------------------------------------------------------
*/

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectBack(string $message): void
{
    $_SESSION['error'] = $message;
    header('Location: report_requests.php');
    exit;
}

function isFinalPaymentVerified(mysqli $conn, string $psgaNo): bool
{
    $psgaNo = trim($psgaNo);

    if ($psgaNo === '') {
        return false;
    }

    $stmt = $conn->prepare("
        SELECT 1
        FROM nleta_offer_master o
        INNER JOIN payment_schedule ps
            ON ps.Offer_ID = o.id
        LEFT JOIN payment_transactions pt
            ON pt.Schedule_ID = ps.Schedule_ID
        WHERE
            TRIM(COALESCE(o.psga_no, '')) = ?
            AND ps.Payment_Stage = 'Final'
            AND (
                LOWER(TRIM(COALESCE(ps.Status, ''))) = 'verified'
                OR LOWER(TRIM(COALESCE(pt.Status, ''))) = 'verified'
            )
        LIMIT 1
    ");

    if (!$stmt) {
        error_log('NLETA report final-payment gate prepare error: ' . $conn->error);
        return false;
    }

    $stmt->bind_param('s', $psgaNo);

    if (!$stmt->execute()) {
        error_log('NLETA report final-payment gate execute error: ' . $stmt->error);
        $stmt->close();
        return false;
    }

    $found = $stmt->get_result()->fetch_row();
    $stmt->close();

    return !empty($found);
}

function istDateOnly($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }

    $raw = trim((string)$value);

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
        $dt = DateTime::createFromFormat('Y-m-d', $raw);
        return $dt ? $dt->format('d / m / Y') : h($raw);
    }

    try {
        $dt = new DateTime($raw, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d / m / Y');
    } catch (Throwable $e) {
        return h($raw);
    }
}

function istDateTime($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d / m / Y h:i A') . ' IST';
    } catch (Throwable $e) {
        return '—';
    }
}

function checklistResult(array $item): string
{
    $value = $item['result']
        ?? $item['status']
        ?? $item['value']
        ?? '';

    $value = strtoupper(trim((string)$value));

    return match ($value) {
        'OK', 'PASS', 'PASSED', 'COMPLIED', 'COMPLY' => 'C',
        'FAIL', 'FAILED', 'NON-COMPLIED', 'NON COMPLIED',
        'NON_COMPLIED', 'NOT COMPLIED' => 'NC',
        'NOT APPLICABLE', 'NOT-APPLICABLE' => 'NA',
        'CONDITIONAL ACCEPTANCE' => 'CA',
        default => $value
    };
}

function checklistRemark(array $item): string
{
    foreach ([
        'remark',
        'engineering_remark',
        'conditional_acceptance_remark',
        'ca_remark',
        'recommendation',
        'observation',
        'condition'
    ] as $key) {
        if (isset($item[$key]) && trim((string)$item[$key]) !== '') {
            return trim((string)$item[$key]);
        }
    }

    return '';
}

function checklistJustification(array $item): string
{
    foreach ([
        'technical_justification',
        'justification',
        'ca_technical_justification'
    ] as $key) {
        if (isset($item[$key]) && trim((string)$item[$key]) !== '') {
            return trim((string)$item[$key]);
        }
    }

    return '';
}

function normalizePriority($value): string
{
    $value = strtoupper(trim((string)$value));

    if (preg_match('/\bP([1-4])\b/', $value, $m)) {
        return 'P' . $m[1];
    }

    return '—';
}

function priorityRank(string $priority): int
{
    return match (normalizePriority($priority)) {
        'P1' => 1,
        'P2' => 2,
        'P3' => 3,
        'P4' => 4,
        default => 99
    };
}

function normalizeFloorLabel(string $value): string
{
    $value = trim($value);
    return $value === '' ? '' : preg_replace('/\s+/', ' ', $value);
}

function assetFamily(array $report): string
{
    $source = strtoupper(
        trim(
            (string)(
                ($report['asset_type'] ?? '')
                . ' '
                . ($report['category'] ?? '')
                . ' '
                . ($report['asset_name'] ?? '')
            )
        )
    );

    if (
        strpos($source, 'ESCALATOR') !== false
        || strpos($source, 'TRAVELLATOR') !== false
        || strpos($source, 'MOVING WALK') !== false
        || strpos($source, 'MOVING WALKWAY') !== false
    ) {
        return 'escalator';
    }

    return 'lift';
}

function serviceLabel(string $serviceCode): string
{
    return match ($serviceCode) {
        'PAT' => 'Partial Acceptance Test / Safety Inspection',
        'SAT' => 'Satisfactory Acceptance Test',
        'SCI' => 'Standards Compliance Inspection',
        default => $serviceCode !== '' ? $serviceCode : 'Inspection'
    };
}

function serviceChecklistCode(string $serviceCode, string $family): string
{
    if ($family === 'escalator') {
        return match ($serviceCode) {
            'PAT' => 'ECHK-01',
            'SAT' => 'ECHK-02',
            'SCI' => 'ECHK-03',
            default => 'ECHK'
        };
    }

    return match ($serviceCode) {
        'PAT' => 'CHK-01',
        'SAT' => 'CHK-02',
        'SCI' => 'CHK-03',
        default => 'CHK'
    };
}

function reportDocumentTitle(string $serviceCode): string
{
    return match ($serviceCode) {
        'PAT' => 'PARTIAL ACCEPTANCE TEST / SAFETY INSPECTION REPORT',
        'SAT' => 'SATISFACTORY ACCEPTANCE TEST REPORT',
        'SCI' => 'STANDARDS COMPLIANCE INSPECTION REPORT',
        default => strtoupper($serviceCode . ' INSPECTION REPORT')
    };
}

function reportNumberPrefix(string $serviceCode): string
{
    return in_array($serviceCode, ['PAT', 'SAT', 'SCI'], true)
        ? $serviceCode
        : 'INS';
}

function isCheckpointReference(string $ref, string $serviceCode, string $family): bool
{
    $ref = strtoupper(trim($ref));

    if ($ref === '') {
        return false;
    }

    if ($family === 'escalator') {
        return preg_match(
            '/^E-' . preg_quote($serviceCode, '/') . '-\d{1,4}(?:[-_].*)?$/i',
            $ref
        ) === 1;
    }

    return preg_match(
        '/^' . preg_quote($serviceCode, '/') . '-\d{1,4}(?:[-_].*)?$/i',
        $ref
    ) === 1;
}

function checkpointRefFromItem(array $item, string $fallbackKey = ''): string
{
    foreach ([
        'checkpoint_id',
        'ref_code',
        'reference',
        'checkpoint_ref',
        'code',
        'id'
    ] as $key) {
        if (
            isset($item[$key])
            && !is_array($item[$key])
            && trim((string)$item[$key]) !== ''
        ) {
            return trim((string)$item[$key]);
        }
    }

    return trim($fallbackKey);
}

function checkpointNameFromItem(array $item, string $fallbackRef = ''): string
{
    foreach ([
        'checkpoint_name',
        'description',
        'checkpoint',
        'name',
        'title',
        'label'
    ] as $key) {
        if (
            isset($item[$key])
            && !is_array($item[$key])
            && trim((string)$item[$key]) !== ''
        ) {
            return trim((string)$item[$key]);
        }
    }

    return $fallbackRef !== '' ? $fallbackRef : 'Checkpoint';
}

function buildSavedMetadataIndex(array $checklist): array
{
    $index = [];

    $walk = function ($node, string $fallbackKey = '') use (&$walk, &$index): void {
        if (!is_array($node)) {
            return;
        }

        $ref = checkpointRefFromItem($node, $fallbackKey);

        $hasMetadata =
            $ref !== ''
            &&
            (
                isset($node['checkpoint_id'])
                || isset($node['ref_code'])
                || isset($node['checkpoint_name'])
                || isset($node['priority'])
                || isset($node['section'])
            );

        if ($hasMetadata) {
            $index[strtoupper($ref)] = [
                'checkpoint_id' => $ref,
                'checkpoint_name' => checkpointNameFromItem($node, $ref),
                'priority' => normalizePriority($node['priority'] ?? ''),
                'section' => trim((string)($node['section'] ?? '')),
            ];
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $walk($value, is_string($key) ? $key : '');
            }
        }
    };

    $walk($checklist);

    return $index;
}

function collectChecklistRows(
    array $checklist,
    string $serviceCode,
    string $family,
    array $metadataIndex
): array {
    $rows = [];

    $addRow = static function (
        array $item,
        string $fallbackKey,
        string $floor,
        string $source
    ) use (&$rows, $serviceCode, $family, $metadataIndex): void {
        $result = checklistResult($item);

        if (!in_array($result, ['C', 'NC', 'NA', 'CA'], true)) {
            return;
        }

        $ref = checkpointRefFromItem($item, $fallbackKey);

        if (!isCheckpointReference($ref, $serviceCode, $family)) {
            $metaFallback = $metadataIndex[strtoupper($ref)] ?? null;

            if (
                !is_array($metaFallback)
                || !isCheckpointReference(
                    (string)($metaFallback['checkpoint_id'] ?? ''),
                    $serviceCode,
                    $family
                )
            ) {
                return;
            }

            $ref = (string)$metaFallback['checkpoint_id'];
        }

        $meta = $metadataIndex[strtoupper($ref)] ?? [];

        $savedName = checkpointNameFromItem($item, $ref);
        $savedPriority = normalizePriority($item['priority'] ?? '');

        if ($savedName === $ref && !empty($meta['checkpoint_name'])) {
            $savedName = (string)$meta['checkpoint_name'];
        }

        if ($savedPriority === '—' && !empty($meta['priority'])) {
            $savedPriority = (string)$meta['priority'];
        }

        $section = trim((string)(
            $item['section']
            ?? ($meta['section'] ?? '')
        ));

        $rows[] = [
            'ref_code' => $ref,
            'checkpoint_name' => $savedName,
            'description' => $savedName,
            'priority' => $savedPriority,
            'section' => $section,
            'result' => $result,
            'remark' => checklistRemark($item),
            'technical_justification' => checklistJustification($item),
            'floor' => normalizeFloorLabel(
                $floor !== ''
                    ? $floor
                    : (string)($item['floor'] ?? '')
            ),
            'source' => $source
        ];
    };

    /*
     * 1. Normal/fixed Inspector result rows.
     */
    $resultsRoot =
        isset($checklist['results']) && is_array($checklist['results'])
            ? $checklist['results']
            : (
                isset($checklist['checklist']) && is_array($checklist['checklist'])
                    ? $checklist['checklist']
                    : $checklist
            );

    $walkResults = function ($node, string $fallbackKey = '') use (
        &$walkResults,
        $addRow
    ): void {
        if (!is_array($node)) {
            return;
        }

        /*
         * A node with result is a checkpoint candidate.
         */
        if (
            isset($node['result'])
            || isset($node['status'])
            || isset($node['value'])
        ) {
            $addRow($node, $fallbackKey, '', 'results');
        }

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            $skip = strtoupper(trim((string)$key));

            if (in_array($skip, [
                'CHECKPOINT_METADATA',
                'OWNER_RESPONSIBILITY_CONFIRMATION',
                'OWNER_APPROVAL',
                'SUMMARY',
                'FINAL_STATUS',
                'META',
                'SECTION_REMARKS'
            ], true)) {
                continue;
            }

            $walkResults(
                $value,
                is_string($key) ? $key : ''
            );
        }
    };

    $walkResults($resultsRoot);

    /*
     * 2. Dynamic/floor checklist rows.
     *
     * We do NOT invent floor checkpoint definitions here.
     * Every floor checkpoint must come from the saved Inspector JSON.
     */
    if (
        isset($checklist['floor_checklist'])
        && is_array($checklist['floor_checklist'])
    ) {
        foreach ($checklist['floor_checklist'] as $floorKey => $floorNode) {
            if (!is_array($floorNode)) {
                continue;
            }

            $floorLabel = normalizeFloorLabel((string)$floorKey);

            $walkFloor = function (
                $node,
                string $fallbackKey = ''
            ) use (
                &$walkFloor,
                $addRow,
                $floorLabel
            ): void {
                if (!is_array($node)) {
                    return;
                }

                if (
                    isset($node['result'])
                    || isset($node['status'])
                    || isset($node['value'])
                ) {
                    $addRow(
                        $node,
                        $fallbackKey,
                        $floorLabel,
                        'floor'
                    );
                }

                foreach ($node as $key => $value) {
                    if (!is_array($value)) {
                        continue;
                    }

                    $walkFloor(
                        $value,
                        is_string($key) ? $key : ''
                    );
                }
            };

            $walkFloor($floorNode);
        }
    }

    /*
     * 3. Some saved Inspector versions keep rows in a top-level
     * "checklist_results" / "inspection_results" array.
     */
    foreach (['checklist_results', 'inspection_results', 'items'] as $containerKey) {
        if (
            isset($checklist[$containerKey])
            && is_array($checklist[$containerKey])
        ) {
            $walkResults = function ($node, string $fallbackKey = '') use (
                &$walkResults,
                $addRow
            ): void {
                if (!is_array($node)) {
                    return;
                }

                if (
                    isset($node['result'])
                    || isset($node['status'])
                    || isset($node['value'])
                ) {
                    $addRow($node, $fallbackKey, '', 'results');
                }

                foreach ($node as $key => $value) {
                    if (is_array($value)) {
                        $walkResults(
                            $value,
                            is_string($key) ? $key : ''
                        );
                    }
                }
            };

            $walkResults($checklist[$containerKey]);
        }
    }

    /*
     * Deduplicate. Prefer the row that contains the most saved metadata.
     */
    $unique = [];

    foreach ($rows as $row) {
        $key = strtoupper(
            trim((string)$row['ref_code'])
            . '|'
            . trim((string)$row['floor'])
        );

        if (!isset($unique[$key])) {
            $unique[$key] = $row;
            continue;
        }

        $existing = $unique[$key];

        foreach ([
            'checkpoint_name',
            'priority',
            'section',
            'remark',
            'technical_justification'
        ] as $field) {
            if (
                trim((string)($existing[$field] ?? '')) === ''
                || $existing[$field] === '—'
            ) {
                if (
                    trim((string)($row[$field] ?? '')) !== ''
                    && $row[$field] !== '—'
                ) {
                    $existing[$field] = $row[$field];
                }
            }
        }

        $unique[$key] = $existing;
    }

    return array_values($unique);
}


/* =========================================================
   AUTHENTICATE REQUEST
========================================================= */

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

$requestId = (int)(
    $_GET['request_id']
    ?? $_POST['request_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($requestId <= 0) {
    redirectBack('Invalid report request.');
}


/* =========================================================
   LOAD REQUEST + INSPECTION
========================================================= */

$stmt = $conn->prepare("
    SELECT
        rr.id AS request_id,
        rr.job_id,
        rr.owner_id,
        rr.inspector_id,
        rr.status AS report_status,
        rr.report_path,
        rr.requested_at,
        rr.owner_message,

        mj.id AS maintenance_job_id,
        mj.asset_id,
        mj.owner_id AS job_owner_id,
        mj.status AS job_status,
        mj.job_type,
        mj.checklist,
        mj.remarks,
        mj.owner_remark,
        mj.created_at AS inspection_created_at,
        mj.updated_at AS inspection_updated_at,
        mj.admin_review_status,

        a.asset_name,
        a.display_asset_id,
        a.location,
        a.asset_type,
        a.category,

        ad.number_of_floors,
        ad.served_floors,
        ad.lift_type,
        ad.passenger_capacity,
        ad.load_capacity,
        ad.machine_type,
        ad.escalator_type,
        ad.escalator_rise,
        ad.installation_date,

        owner.name AS owner_name,
        owner.email AS owner_email,

        inspector.name AS inspector_name,
        inspector.email AS inspector_email,

        s.id AS saf_contract_id,
        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.proposed_inspection_date,
        s.status AS saf_status

    FROM report_requests rr

    LEFT JOIN maintenance_jobs mj
        ON mj.id = rr.job_id

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN asset_details ad
        ON ad.asset_id = mj.asset_id

    LEFT JOIN users owner
        ON owner.id = rr.owner_id

    LEFT JOIN users inspector
        ON inspector.id = rr.inspector_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        rr.id = ?
        AND rr.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

        AND EXISTS (
            SELECT 1
            FROM inspector_assignments ia
            WHERE
                ia.job_id = rr.job_id
                AND ia.inspector_id = rr.inspector_id
        )

    LIMIT 1
");

if (!$stmt) {
    redirectBack(
        'Database error while loading report request: ' . $conn->error
    );
}

$stmt->bind_param('ii', $requestId, $inspectorId);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    redirectBack('Unable to load report request: ' . $err);
}

$report = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$report) {
    redirectBack(
        'Report request not found or it is not assigned to you.'
    );
}


/* =========================================================
   SERVICE + ASSET ROUTING
========================================================= */

$serviceCode = strtoupper(
    trim((string)($report['service_code'] ?? ''))
);

if (!in_array($serviceCode, ['PAT', 'SAT', 'SCI'], true)) {
    redirectBack(
        'Unsupported inspection service for report generation: '
        . ($serviceCode !== '' ? $serviceCode : 'unknown')
    );
}

$family = assetFamily($report);

$serviceName = serviceLabel($serviceCode);
$documentTitle = reportDocumentTitle($serviceCode);
$checklistCode = serviceChecklistCode($serviceCode, $family);

$familyLabel = $family === 'escalator'
    ? 'Escalator / Travellator'
    : 'Lift / Elevator';


/* =========================================================
   FINAL PAYMENT VERIFICATION GATE
   ---------------------------------------------------------
   A report may be generated only after the Final payment
   has been VERIFIED by Admin.
========================================================= */

$finalPaymentVerified = isFinalPaymentVerified(
    $conn,
    (string)($report['psga_no'] ?? '')
);

if (!$finalPaymentVerified) {
    redirectBack(
        'Final payment must be verified by Admin before the inspection report can be generated.'
    );
}


/* =========================================================
   REPORT STATUS
========================================================= */

$currentStatus = strtolower(
    trim((string)($report['report_status'] ?? ''))
);

$allowedStatuses = [
    'admin_approved',
    'approved',
    'generated'
];

$hasExistingReport =
    trim((string)($report['report_path'] ?? '')) !== '';

$canGenerate =
    in_array($currentStatus, $allowedStatuses, true)
    ||
    (
        $currentStatus === 'sent_to_owner'
        && $hasExistingReport
    );

if (!$canGenerate) {
    redirectBack(
        'Admin approval is required before generating the '
        . $serviceCode
        . ' report. Current status: '
        . ($currentStatus !== '' ? $currentStatus : 'unknown')
    );
}


/* =========================================================
   DECODE SAVED INSPECTOR CHECKLIST
========================================================= */

$checklistData = [];

$rawChecklist = (string)($report['checklist'] ?? '');

if (trim($rawChecklist) !== '') {
    $decoded = json_decode($rawChecklist, true);

    if (
        json_last_error() === JSON_ERROR_NONE
        && is_array($decoded)
    ) {
        $checklistData = $decoded;
    }
}

if (!$checklistData) {
    redirectBack(
        $serviceCode
        . ' report cannot be generated because the saved Inspector checklist is empty or invalid.'
    );
}


/* =========================================================
   SAVED CHECKLIST METADATA
   ---------------------------------------------------------
   IMPORTANT:
   No hardcoded checkpoint/priority master is used here.
========================================================= */

$metadataIndex = buildSavedMetadataIndex($checklistData);

$rows = collectChecklistRows(
    $checklistData,
    $serviceCode,
    $family,
    $metadataIndex
);

if (!$rows) {
    redirectBack(
        $serviceCode
        . ' report cannot be generated because no valid saved '
        . $familyLabel
        . ' '
        . $serviceCode
        . ' checkpoint results were found in the Inspector checklist.'
    );
}


/* =========================================================
   FINAL FINDINGS
========================================================= */

$finalFindings = [];

foreach ($rows as $row) {
    if (
        $row['result'] === 'NC'
        || $row['result'] === 'CA'
    ) {
        $finalFindings[] = $row;
    }
}

usort(
    $finalFindings,
    static function (array $a, array $b): int {
        $pa = priorityRank((string)($a['priority'] ?? ''));
        $pb = priorityRank((string)($b['priority'] ?? ''));

        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        $floorCompare = strnatcasecmp(
            (string)($a['floor'] ?? ''),
            (string)($b['floor'] ?? '')
        );

        if ($floorCompare !== 0) {
            return $floorCompare;
        }

        return strnatcasecmp(
            (string)($a['ref_code'] ?? ''),
            (string)($b['ref_code'] ?? '')
        );
    }
);


/* =========================================================
   OUTCOME
========================================================= */

$totalNC = 0;
$totalCA = 0;

foreach ($finalFindings as $finding) {
    $result = strtoupper(
        trim((string)($finding['result'] ?? ''))
    );

    if ($result === 'NC') {
        $totalNC++;
    } elseif ($result === 'CA') {
        $totalCA++;
    }
}

$complianceOutcome =
    $totalNC > 0
        ? 'Non-Compliant'
        : (
            $totalCA > 0
                ? 'Conditional Acceptance'
                : 'Compliant'
        );


/* =========================================================
   PRIORITY SUMMARY
   ---------------------------------------------------------
   Uses the saved Inspector priority only.
========================================================= */

$priorityCounts = [
    'P1' => 0,
    'P2' => 0,
    'P3' => 0,
    'P4' => 0
];

foreach ($finalFindings as $finding) {
    if (
        strtoupper(trim((string)$finding['result']))
        !== 'NC'
    ) {
        continue;
    }

    $priority = normalizePriority(
        $finding['priority'] ?? ''
    );

    if (isset($priorityCounts[$priority])) {
        $priorityCounts[$priority]++;
    }
}


/* =========================================================
   OWNER-WISE REPORT NUMBER
========================================================= */

$reportOwnerId = (int)(
    $report['owner_id']
    ?? $report['job_owner_id']
    ?? 0
);

$reportSafNo = trim(
    (string)($report['saf_no'] ?? '')
);

if ($reportOwnerId <= 0) {
    redirectBack(
        'Owner could not be resolved for report numbering.'
    );
}

if ($reportSafNo === '') {
    redirectBack(
        'SAF number is missing for this report.'
    );
}

$reportPrefix = reportNumberPrefix($serviceCode);


/* =========================================================
   REPORT NUMBER COLUMN
========================================================= */

$columns = [];

$columnResult = $conn->query(
    'SHOW COLUMNS FROM report_requests'
);

if ($columnResult) {
    while ($column = $columnResult->fetch_assoc()) {
        $columns[] = (string)$column['Field'];
    }
}

if (!in_array('report_number', $columns, true)) {
    if (!$conn->query(
        "ALTER TABLE report_requests
         ADD COLUMN report_number VARCHAR(100) NULL
         AFTER report_path"
    )) {
        redirectBack(
            'Unable to create report_number field: ' . $conn->error
        );
    }

    $columns[] = 'report_number';
}


/* =========================================================
   EXISTING REPORT NUMBER
========================================================= */

$existingReportNumber = '';

$existingNumberStmt = $conn->prepare("
    SELECT report_number
    FROM report_requests
    WHERE id = ?
      AND inspector_id = ?
    LIMIT 1
");

if (!$existingNumberStmt) {
    redirectBack(
        'Unable to read existing report number: ' . $conn->error
    );
}

$existingNumberStmt->bind_param(
    'ii',
    $requestId,
    $inspectorId
);

if (!$existingNumberStmt->execute()) {
    $err = $existingNumberStmt->error;
    $existingNumberStmt->close();

    redirectBack(
        'Unable to read existing report number: ' . $err
    );
}

$existingNumberRow =
    $existingNumberStmt->get_result()->fetch_assoc();

$existingNumberStmt->close();

$existingReportNumber = trim(
    (string)($existingNumberRow['report_number'] ?? '')
);


/* =========================================================
   NUMBER TRANSACTION
========================================================= */

$reportSequence = 0;
$reportCode = '';
$reportNumber = '';

$conn->begin_transaction();

try {

    if ($existingReportNumber !== '') {

        /*
         * Regeneration keeps the same issued report number.
         */
        $reportNumber = $existingReportNumber;

        if (preg_match(
            '/^(' . preg_quote($reportPrefix, '/') . '-R\d+)-/i',
            $reportNumber,
            $m
        )) {
            $reportCode = strtoupper((string)$m[1]);
        } else {
            $reportCode = $reportPrefix . '-R01';
        }

        if (preg_match(
            '/^' . preg_quote($reportPrefix, '/') . '-R(\d+)-/i',
            $reportNumber,
            $m2
        )) {
            $reportSequence = (int)$m2[1];
        }

    } else {

        $seed = $conn->prepare("
            INSERT INTO nleta_report_sequences
                (owner_id, service_code, last_no)
            VALUES
                (?, ?, 0)
            ON DUPLICATE KEY UPDATE
                owner_id = VALUES(owner_id)
        ");

        if (!$seed) {
            throw new Exception(
                'Unable to prepare report sequence: ' . $conn->error
            );
        }

        $seed->bind_param(
            'is',
            $reportOwnerId,
            $serviceCode
        );

        if (!$seed->execute()) {
            throw new Exception(
                'Unable to initialize report sequence: '
                . $seed->error
            );
        }

        $seed->close();

        $next = $conn->prepare("
            UPDATE nleta_report_sequences
            SET last_no = last_no + 1
            WHERE owner_id = ?
              AND service_code = ?
            LIMIT 1
        ");

        if (!$next) {
            throw new Exception(
                'Unable to prepare next report number: ' . $conn->error
            );
        }

        $next->bind_param(
            'is',
            $reportOwnerId,
            $serviceCode
        );

        if (!$next->execute()) {
            throw new Exception(
                'Unable to allocate next report number: '
                . $next->error
            );
        }

        $next->close();

        $read = $conn->prepare("
            SELECT last_no
            FROM nleta_report_sequences
            WHERE owner_id = ?
              AND service_code = ?
            LIMIT 1
        ");

        if (!$read) {
            throw new Exception(
                'Unable to read report sequence: ' . $conn->error
            );
        }

        $read->bind_param(
            'is',
            $reportOwnerId,
            $serviceCode
        );

        if (!$read->execute()) {
            throw new Exception(
                'Unable to read report sequence: ' . $read->error
            );
        }

        $seqRow = $read->get_result()->fetch_assoc();
        $read->close();

        $reportSequence = (int)(
            $seqRow['last_no'] ?? 0
        );

        if ($reportSequence <= 0) {
            throw new Exception(
                'Invalid report sequence generated.'
            );
        }

        $reportCode =
            $reportPrefix
            . '-R'
            . str_pad(
                (string)$reportSequence,
                2,
                '0',
                STR_PAD_LEFT
            );

        $reportNumber =
            $reportCode
            . '-'
            . $reportSafNo;
    }

} catch (Throwable $e) {

    $conn->rollback();

    redirectBack(
        'Report numbering failed: ' . $e->getMessage()
    );
}

if (
    $reportCode === ''
    || $reportNumber === ''
) {
    $conn->rollback();

    redirectBack(
        'Report number could not be generated.'
    );
}


/* =========================================================
   REPORT SUMMARY
========================================================= */

$clientName =
    trim((string)($report['owner_name'] ?? ''))
    ?: '—';

$site =
    trim((string)($report['location'] ?? ''))
    ?: '—';

$assetDisplay =
    trim((string)($report['display_asset_id'] ?? ''))
    ?: trim((string)($report['asset_name'] ?? ''))
    ?: '—';

$inspectionId =
    trim((string)($report['saf_no'] ?? ''))
    ?: trim((string)($report['psga_no'] ?? ''))
    ?: '—';

$inspector =
    trim((string)($report['inspector_name'] ?? ''))
    ?: '—';

$inspectionDate = istDateOnly(
    $report['proposed_inspection_date']
    ?? $report['inspection_created_at']
    ?? null
);

$requestedDate = istDateTime(
    $report['requested_at'] ?? null
);

$generatedDate =
    date('d / m / Y h:i A')
    . ' IST';

$unitsInspected = '1';


/* =========================================================
   ASSET DETAILS
   ---------------------------------------------------------
   Read directly from the existing asset_details profile.
   No checklist/master values are used here.
========================================================= */

$installationYear = '—';

if (
    !empty($report['installation_date'])
) {
    try {
        $installationDateValue = new DateTime(
            (string)$report['installation_date'],
            new DateTimeZone('UTC')
        );

        $installationYear =
            $installationDateValue->format('Y');

    } catch (Throwable $e) {
        $installationYear = '—';
    }
}

$numberOfFloors =
    trim((string)($report['number_of_floors'] ?? ''));

$servedFloors =
    trim((string)($report['served_floors'] ?? ''));

/*
 * Report should show only the numeric number of floors/levels.
 * Do not display floor labels such as:
 * G, 1, 2, 3, 4
 *
 * Example:
 *   5
 * instead of:
 *   5 floor(s) — G,1,2,3,4
 */
if ($numberOfFloors !== '') {
    $levelsServed = $numberOfFloors;
} elseif ($servedFloors !== '') {
    /*
     * Fallback: if number_of_floors is empty, count the
     * served-floor entries and show only that number.
     */
    $floorParts = preg_split(
        '/\s*[,;|]+\s*/',
        $servedFloors,
        -1,
        PREG_SPLIT_NO_EMPTY
    );

    $levelsServed = !empty($floorParts)
        ? (string)count($floorParts)
        : $servedFloors;
} else {
    $levelsServed = '—';
}

$liftType =
    trim((string)($report['lift_type'] ?? ''))
    ?: '—';

$passengerCapacity =
    trim((string)($report['passenger_capacity'] ?? ''));

$passengerCapacityDisplay =
    $passengerCapacity !== ''
        ? $passengerCapacity . ' persons'
        : '—';

$machineType =
    trim((string)($report['machine_type'] ?? ''))
    ?: '—';

$ratedLoadCapacity =
    trim((string)($report['load_capacity'] ?? ''));

$ratedLoadCapacityDisplay =
    $ratedLoadCapacity !== ''
        ? $ratedLoadCapacity . ' kg'
        : '—';

$escalatorType =
    trim((string)($report['escalator_type'] ?? ''))
    ?: '—';

$escalatorRise =
    trim((string)($report['escalator_rise'] ?? ''));

$escalatorRiseDisplay =
    $escalatorRise !== ''
        ? $escalatorRise . ' m'
        : '—';


/* =========================================================
   REPORT DIRECTORY
========================================================= */

$reportDirectory =
    dirname(__DIR__)
    . DIRECTORY_SEPARATOR
    . 'reports'
    . DIRECTORY_SEPARATOR
    . 'generated'
    . DIRECTORY_SEPARATOR;

if (!is_dir($reportDirectory)) {
    if (!mkdir($reportDirectory, 0755, true)) {
        $conn->rollback();
        redirectBack(
            'Unable to create reports/generated directory.'
        );
    }
}

if (!is_writable($reportDirectory)) {
    $conn->rollback();
    redirectBack(
        'The reports/generated folder is not writable.'
    );
}


/* =========================================================
   FILE NAME
========================================================= */

$safeAsset = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $assetDisplay
);

$safeAsset = trim((string)$safeAsset, '_');

if ($safeAsset === '') {
    $safeAsset = 'asset';
}

$safeReportNumber = preg_replace(
    '/[^A-Za-z0-9_-]/',
    '_',
    $reportNumber
);

$fileName =
    $safeReportNumber
    . '_'
    . $safeAsset
    . '_'
    . $requestId
    . '.pdf';

$pdfPath =
    $reportDirectory
    . $fileName;

$relativePath =
    'reports/generated/'
    . $fileName;


/* =========================================================
   DOMPDF
========================================================= */

$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php'
];

$autoloadLoaded = false;

foreach ($autoloadCandidates as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        $autoloadLoaded = true;
        break;
    }
}

if (
    !$autoloadLoaded
    || !class_exists('Dompdf\\Dompdf')
) {
    $conn->rollback();
    redirectBack('Dompdf is not installed.');
}

$options = new \Dompdf\Options();

$options->set('isRemoteEnabled', true);
$options->set('isHtml5ParserEnabled', true);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new \Dompdf\Dompdf($options);


/* =========================================================
   WATERMARK
========================================================= */

$watermarkLogoData = '';

$watermarkLogoPath =
    __DIR__
    . DIRECTORY_SEPARATOR
    . '..'
    . DIRECTORY_SEPARATOR
    . 'assets'
    . DIRECTORY_SEPARATOR
    . 'images'
    . DIRECTORY_SEPARATOR
    . 'nleta-logo.jpeg';

if (
    is_file($watermarkLogoPath)
    && is_readable($watermarkLogoPath)
) {
    $watermarkLogoBinary =
        file_get_contents($watermarkLogoPath);

    if (
        $watermarkLogoBinary !== false
        && $watermarkLogoBinary !== ''
    ) {
        $watermarkLogoData =
            'data:image/jpeg;base64,'
            . base64_encode($watermarkLogoBinary);
    }
}


/* =========================================================
   FINDINGS HTML
========================================================= */

$findingsHtml = '';

if (!$finalFindings) {

    $findingsHtml = '
<tr>
<td colspan="5" class="empty-row">
No NC or Conditional Acceptance findings recorded.
</td>
</tr>';

} else {

    foreach ($finalFindings as $item) {

        $itemText =
            '<strong>'
            . h($item['ref_code'])
            . '</strong>';

        if (
            trim((string)($item['floor'] ?? '')) !== ''
        ) {
            $itemText .=
                '<br>Floor: '
                . h($item['floor']);
        }

        $checkpointName =
            trim((string)($item['checkpoint_name'] ?? ''));

        if ($checkpointName !== '') {
            $itemText .=
                '<br>'
                . h($checkpointName);
        }

        $findingResult =
            strtoupper(
                trim((string)($item['result'] ?? ''))
            );

        $resultClass =
            $findingResult === 'CA'
                ? 'finding-result-ca'
                : 'finding-result-nc';

        $priority =
            normalizePriority(
                $item['priority'] ?? ''
            );

        $basis = '';

        if (
            trim((string)($item['remark'] ?? '')) !== ''
        ) {
            $basis =
                nl2br(
                    h((string)$item['remark'])
                );
        }

        if (
            $findingResult === 'CA'
            && trim(
                (string)($item['technical_justification'] ?? '')
            ) !== ''
        ) {

            if ($basis !== '') {
                $basis .= '<br>';
            }

            $basis .=
                '<strong>Technical Justification:</strong><br>'
                .
                nl2br(
                    h(
                        (string)$item['technical_justification']
                    )
                );
        }

        if ($basis === '') {
            $basis = '—';
        }

        $section =
            trim((string)($item['section'] ?? ''));

        if ($section !== '') {
            $basis =
                '<span class="small-note">'
                . 'Section: '
                . h($section)
                . '</span><br>'
                . $basis;
        }

        $findingsHtml .= '
<tr>
<td>' . $itemText . '</td>
<td class="risk">' . h($priority) . '</td>
<td class="' . $resultClass . '">' .
    h($findingResult) . '</td>
<td class="recommendation">' . $basis . '</td>
<td>' . h($serviceCode) . '</td>
</tr>';
    }
}


/* =========================================================
   REPORT HTML
========================================================= */

$html = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>' . h($reportNumber) . ' — ' .
    h($documentTitle) . '</title>

<style>

@page {
    size: A4 portrait;
    margin: 14mm 15mm 16mm 15mm;
}

body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 9px;
    color: #111;
    margin: 0;
    padding: 0;
}

.report-watermark {
    position: fixed;
    left: 50%;
    top: 50%;
    width: 175mm;
    height: auto;
    transform: translate(-50%, -50%);
    opacity: 0.08;
    z-index: -1;
    text-align: center;
}

.report-watermark img {
    width: 100%;
    height: auto;
    display: block;
}

.header {
    border-bottom: 1px solid #222;
    padding-bottom: 5px;
    margin-bottom: 8px;
}

.header-table {
    width: 100%;
    border-collapse: collapse;
}

.header-left {
    text-align: left;
    font-weight: bold;
    font-size: 9px;
}

.header-right {
    text-align: right;
    font-size: 8px;
}

.title {
    text-align: center;
    font-size: 15px;
    font-weight: bold;
    margin: 6px 0 9px;
}

.section-title {
    background: #f3f3f3;
    border: 1px solid #222;
    padding: 4px 6px;
    font-size: 9px;
    font-weight: bold;
    margin-top: 8px;
    margin-bottom: 4px;
}

.purpose {
    border: 1px solid #777;
    padding: 5px 6px;
    line-height: 1.35;
    margin: 5px 0 8px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

.control th,
.control td,
.summary th,
.summary td,
.findings th,
.findings td {
    border: 1px solid #222;
    padding: 3px 5px;
    vertical-align: top;
}

.control th,
.summary th {
    font-weight: normal;
    text-align: left;
    width: 42%;
}

.control thead th,
.summary thead th {
    font-weight: bold;
    text-align: center;
    background: #f3f3f3;
}

.findings {
    page-break-inside: auto;
}

.findings thead {
    display: table-header-group;
}

.findings tr {
    page-break-inside: avoid;
}

.findings th {
    background: #f3f3f3;
    font-weight: bold;
    text-align: center;
    font-size: 8px;
}

.findings td {
    font-size: 8px;
    line-height: 1.25;
}

.finding-result-nc {
    font-weight: bold;
    text-align: center;
}

.finding-result-ca {
    font-weight: bold;
    text-align: center;
}

.risk {
    font-weight: bold;
    text-align: center;
}

.recommendation {
    white-space: normal;
}

.empty-row {
    text-align: center;
    padding: 8px !important;
}

.declaration {
    font-size: 8.5px;
    line-height: 1.4;
    margin-top: 10px;
}

.signature-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 13px;
}

.signature-table td {
    width: 50%;
    border: 1px solid #222;
    padding: 8px;
    height: 40px;
    vertical-align: top;
}

.signature-line {
    margin-top: 18px;
    border-top: 1px solid #222;
    padding-top: 3px;
    font-size: 8px;
}

.end-document {
    text-align: center;
    font-weight: bold;
    font-size: 9px;
    margin-top: 12px;
}

.footer {
    position: fixed;
    bottom: -9mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 7px;
    color: #555;
}

.small-note {
    font-size: 7.5px;
    color: #444;
}

</style>
</head>

<body>

' . (
    $watermarkLogoData !== ''
        ? '<div class="report-watermark" aria-hidden="true">
             <img src="' . $watermarkLogoData . '" alt="">
           </div>'
        : ''
) . '

<div class="header">
<table class="header-table">
<tr>
<td class="header-left">
National Lift Escalator Testing Agency (NLETA)
</td>
<td class="header-right">
ARCHIVE 11 | REV.1
</td>
</tr>
</table>
</div>

<div class="title">
' . h($reportNumber) . ' — ' .
    h($documentTitle) . '
</div>

<div class="section-title">
Document Control
</div>

<table class="control">

<tr>
<th>Archive No.</th>
<td>11</td>
</tr>

<tr>
<th>Document Code</th>
<td>' . h($reportCode) . '</td>
</tr>

<tr>
<th>Revision</th>
<td>Rev.1</td>
</tr>

<tr>
<th>Classification</th>
<td>Customer Report</td>
</tr>

<tr>
<th>Checklist</th>
<td>' . h($checklistCode) . ' Rev.1</td>
</tr>

<tr>
<th>Asset Category</th>
<td>' . h($familyLabel) . '</td>
</tr>

</table>

<div class="purpose">

<strong>Report Purpose-</strong>

' . h($reportNumber) . ' is the official engineering report issued after
completion of the ' . h($serviceName) . '. It summarizes the
inspection scope, saved Inspector findings, priority classification,
technical observations and overall compliance outcome.

</div>

<div class="section-title">
Inspection Summary
</div>

<table class="summary">

<tr>
<th>Client</th>
<td>' . h($clientName) . '</td>
</tr>

<tr>
<th>Site</th>
<td>' . h($site) . '</td>
</tr>

<tr>
<th>Inspection ID</th>
<td>' . h($inspectionId) . '</td>
</tr>

<tr>
<th>Asset ID</th>
<td>' . h($assetDisplay) . '</td>
</tr>

<tr>
<th>Asset Type</th>
<td>' . h((string)($report['asset_type'] ?? '')) . '</td>
</tr>

<tr>
<th>Service</th>
<td>' . h($serviceCode . ' — ' . $serviceName) . '</td>
</tr>

<tr>
<th>Inspection Date</th>
<td>' . h($inspectionDate) . '</td>
</tr>

<tr>
<th>Inspector</th>
<td>' . h($inspector) . '</td>
</tr>

<tr>
<th>AMC Agency</th>
<td>—</td>
</tr>

<tr>
<th>Units Inspected</th>
<td>' . h($unitsInspected) . '</td>
</tr>

</table>

<div class="section-title">
Assets Details
</div>

<table class="summary">

' . (
    $family === 'escalator'
        ? '
<tr>
<th>Escalator Type</th>
<td>' . h($escalatorType) . '</td>
</tr>

<tr>
<th>Escalator Rise</th>
<td>' . h($escalatorRiseDisplay) . '</td>
</tr>

<tr>
<th>Installation Year</th>
<td>' . h($installationYear) . '</td>
</tr>
'
        : '
<tr>
<th>Lift Type</th>
<td>' . h($liftType) . '</td>
</tr>

<tr>
<th>Number of Floors / Levels Served</th>
<td>' . h($levelsServed) . '</td>
</tr>

<tr>
<th>Passenger Capacity</th>
<td>' . h($passengerCapacityDisplay) . '</td>
</tr>

<tr>
<th>Machine Type</th>
<td>' . h($machineType) . '</td>
</tr>

<tr>
<th>Rated Load Capacity</th>
<td>' . h($ratedLoadCapacityDisplay) . '</td>
</tr>

<tr>
<th>Installation Year</th>
<td>' . h($installationYear) . '</td>
</tr>
'
) . '

</table>

<div class="section-title">
Engineering Findings
</div>

<table class="findings">

<thead>
<tr>
<th style="width:28%;">Checkpoint</th>
<th style="width:12%;">Priority</th>
<th style="width:12%;">Status</th>
<th style="width:43%;">Recommendation / Technical Basis</th>
<th style="width:5%;">Service</th>
</tr>
</thead>

<tbody>
' . $findingsHtml . '
</tbody>

</table>

<div class="section-title">
Final NC Priority Summary — ' . h($serviceCode) . '
</div>

<table class="control">

<tr>
<th>P1 — Critical / High Priority NC</th>
<td>' . (int)$priorityCounts['P1'] . '</td>
</tr>

<tr>
<th>P2 — High Priority NC</th>
<td>' . (int)$priorityCounts['P2'] . '</td>
</tr>

<tr>
<th>P3 — Moderate Priority NC</th>
<td>' . (int)$priorityCounts['P3'] . '</td>
</tr>

<tr>
<th>P4 — Lower Priority NC</th>
<td>' . (int)$priorityCounts['P4'] . '</td>
</tr>

<tr>
<th>CA — Conditional Acceptance Checkpoints</th>
<td>' . (int)$totalCA . '</td>
</tr>

</table>

<div class="section-title">
Compliance Outcome
</div>

<table class="control">

<thead>
<tr>
<th style="width:50%;text-align:center;font-weight:bold;">
Result
</th>

<th style="width:50%;text-align:center;font-weight:bold;">
Selection
</th>
</tr>
</thead>

<tr>
<td>Compliant</td>
<td>' .
(
    $complianceOutcome === 'Compliant'
        ? '☒'
        : '☐'
)
. '</td>
</tr>

<tr>
<td>Conditional Acceptance</td>
<td>' .
(
    $complianceOutcome === 'Conditional Acceptance'
        ? '☒'
        : '☐'
)
. '</td>
</tr>

<tr>
<td>Non-Compliant</td>
<td>' .
(
    $complianceOutcome === 'Non-Compliant'
        ? '☒'
        : '☐'
)
. '</td>
</tr>

<tr>
<td>Critical Safety Risk</td>
<td>☐</td>
</tr>

</table>

<div class="section-title">
Report Generation Information
</div>

<table class="control">

<tr>
<th>Report Number</th>
<td>' . h($reportNumber) . '</td>
</tr>

<tr>
<th>Checklist Revision</th>
<td>' . h($checklistCode) . ' Rev.1</td>
</tr>

<tr>
<th>Generated</th>
<td>' . h($generatedDate) . '</td>
</tr>

</table>

<div class="declaration">

<strong>Inspector Declaration-</strong>

I certify that this report is based on the saved Inspector
' . h($serviceCode) . ' inspection checklist for the identified
' . h($familyLabel) . ', including the checkpoint metadata,
priority, results and documented technical observations saved
during the inspection.

</div>

<table class="signature-table">

<tr>

<td>

<strong>Inspector</strong>

<div class="signature-line">
Name: ' . h($inspector) . '
</div>

<div class="signature-line">
Date: ' . h($inspectionDate) . '
</div>

</td>

<td>

<strong>National Lift Escalator Testing Agency</strong>

<div class="signature-line">
Authorized Signatory
</div>

<div class="signature-line">
Date: ' . h(istDateOnly(date('Y-m-d'))) . '
</div>

</td>

</tr>

</table>

<div class="end-document">

END OF ARCHIVE 11 — ' . h($reportCode) . ' (Rev.1)

</div>

<div class="footer">

NLETA | ARCHIVE 11 | ' . h($reportCode) . ' (Rev.1) |
' . h($reportNumber) . '

</div>

</body>
</html>';


/* =========================================================
   GENERATE PDF
========================================================= */

try {

    if (file_exists($pdfPath)) {
        @unlink($pdfPath);
    }

    $dompdf->loadHtml($html);

    $dompdf->setPaper(
        'A4',
        'portrait'
    );

    $dompdf->render();

    $pdfOutput = $dompdf->output();

    if ($pdfOutput === '') {
        throw new RuntimeException(
            'PDF output is empty.'
        );
    }

    $saved = file_put_contents(
        $pdfPath,
        $pdfOutput,
        LOCK_EX
    );

    if (
        $saved === false
        || $saved <= 0
    ) {
        throw new RuntimeException(
            'Unable to save generated PDF.'
        );
    }

    clearstatcache(
        true,
        $pdfPath
    );

    if (
        !file_exists($pdfPath)
        || filesize($pdfPath) <= 0
    ) {
        throw new RuntimeException(
            'Generated PDF could not be verified.'
        );
    }

} catch (Throwable $e) {

    $conn->rollback();

    redirectBack(
        'Report generation failed: ' . $e->getMessage()
    );
}


/* =========================================================
   SAVE REPORT PATH + STATUS
========================================================= */

$setParts = [
    "status = 'generated'",
    'report_path = ?',
    'report_number = ?'
];

$types = 'ss';

$values = [
    $relativePath,
    $reportNumber
];

if (in_array('report_file', $columns, true)) {
    $setParts[] = 'report_file = ?';
    $types .= 's';
    $values[] = $relativePath;
}

if (in_array('generated_at', $columns, true)) {
    $setParts[] = 'generated_at = NOW()';
}

if (in_array('updated_at', $columns, true)) {
    $setParts[] = 'updated_at = NOW()';
}

$types .= 'ii';

$values[] = $requestId;
$values[] = $inspectorId;

$updateSql = "
    UPDATE report_requests
    SET " . implode(', ', $setParts) . "
    WHERE id = ?
      AND inspector_id = ?
    LIMIT 1
";

$update = $conn->prepare($updateSql);

if (!$update) {
    $conn->rollback();

    redirectBack(
        'PDF generated, but database update failed: '
        . $conn->error
    );
}

$bind = [$types];

foreach ($values as $key => $value) {
    $bind[] = &$values[$key];
}

call_user_func_array(
    [$update, 'bind_param'],
    $bind
);

if (!$update->execute()) {

    $err = $update->error;

    $update->close();
    $conn->rollback();

    redirectBack(
        'PDF generated, but report status could not be saved: '
        . $err
    );
}

$update->close();

if (!$conn->commit()) {
    $conn->rollback();

    redirectBack(
        'Report generated, but the report-number transaction could not be committed.'
    );
}


/* =========================================================
   SUCCESS
========================================================= */

if (
    $currentStatus === 'generated'
    ||
    (
        $currentStatus === 'sent_to_owner'
        && $hasExistingReport
    )
) {

    $_SESSION['success'] =
        $reportNumber
        . ' regenerated successfully using the saved Inspector '
        . $familyLabel
        . ' '
        . $serviceCode
        . ' checklist data.';

} else {

    $_SESSION['success'] =
        $reportNumber
        . ' generated successfully using the saved Inspector '
        . $familyLabel
        . ' '
        . $serviceCode
        . ' checklist data.';
}

header('Location: report_requests.php');
exit;
?>