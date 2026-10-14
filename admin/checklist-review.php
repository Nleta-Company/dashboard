<?php
/* =========================================================
   NLETA ADMIN — SAF / PAT CHECKLIST REVIEW
   ---------------------------------------------------------
   READ-ONLY CHECKLIST REVIEW + FINAL CERTIFICATE WORKFLOW

   Rev.1 CONTROL RULES
   ---------------------------------------------------------
   - Result displayed as C / NC / NA / CA
   - Photo/video evidence remains optional
   - Technical Justification is shown for CA
   - Floor-wise rows are read from the submitted Inspector checklist JSON
   - All displayed date/time values are IST
   - Owner sees only NC findings in R01
   - R01 contains Indicative Category, never Star Rating
   - Star Rating is calculated automatically and is visible
     only in the Admin Certificate
   - Compliance = C / (C + NC) * 100
   - NA excluded
  
   CERTIFICATE WORKFLOW
   ---------------------------------------------------------
   Final R01 report sent to Owner
             ↓
   Owner requests Certificate
             ↓
   certificate_status = requested
             ↓
   Admin opens this page
             ↓
   Automatic score/category/star calculation
             ↓
   Generate Certificate
             ↓
   certificate_status = generated
             ↓
   Send Certificate to Owner
             ↓
   certificate_status = sent
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';

require_role('admin');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
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

function formatIST(
    $value,
    string $sourceTimezone = 'UTC',
    string $format = 'd / m / Y h:i A'
): string {
    if (empty($value)) {
        return '—';
    }

    try {
        $dt = new DateTime(
            (string)$value,
            new DateTimeZone($sourceTimezone)
        );

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return $dt->format($format) . ' IST';

    } catch (Throwable $e) {
        return h($value);
    }
}

function formatDbUtcToIST(
    $value,
    string $format = 'd / m / Y h:i A'
): string {
    if (empty($value)) {
        return '—';
    }

    try {
        $raw = trim((string)$value);

        $hasTimezone = (bool)preg_match(
            '/(?:Z|[+-]\d{2}:?\d{2})$/i',
            $raw
        );

        $dt = $hasTimezone
            ? new DateTime($raw)
            : new DateTime($raw, new DateTimeZone('UTC'));

        $dt->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return $dt->format($format) . ' IST';

    } catch (Throwable $e) {
        return h($value);
    }
}

function dateOnly($value): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $dt = new DateTime(
            (string)$value,
            new DateTimeZone('Asia/Kolkata')
        );

        return $dt->format('d / m / Y');

    } catch (Throwable $e) {
        return h($value);
    }
}

function resultClass(string $result): string
{
    return match (strtoupper(trim($result))) {
        'C', 'OK' => 'result-ok',
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
        'OK', 'C' => 'C',
        'NC'      => 'NC',
        'NA'      => 'NA',
        'CA'      => 'CA',
        default   => $result !== '' ? $result : '—',
    };
}


/* =========================================================
   CHECKPOINT NAME + PRIORITY + EVIDENCE DISPLAY
   ---------------------------------------------------------
   Admin review must show:
   1. Checkpoint ID
   2. Priority
   3. Description = actual checkpoint name
   4. Photo + Video independently
   5. Document when available

   Metadata source:
   - Saved Inspector checklist JSON only
   - No PAT/SAT/SCI hardcoded fallback
========================================================= */

function firstNonEmptyValue(array $item, array $keys): string
{
    foreach ($keys as $key) {
        if (
            isset($item[$key])
            && is_scalar($item[$key])
        ) {
            $value = trim((string)$item[$key]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    return '';
}

function checkpointMetaForAdmin(
    string $ref,
    array $item,
    array $checklist = []
): array {

    /*
     * SOURCE OF TRUTH = SAVED INSPECTOR SUBMISSION
     * ---------------------------------------------------------
     * Admin reads checkpoint name, priority and section from the
     * submitted inspection JSON. No separate PAT/SAT/SCI master
     * map is required for display.
     */

    $name = firstNonEmptyValue(
        $item,
        [
            'checkpoint_name',
            'checkpoint',
            'description',
            'engineering_checkpoint',
            'text',
            'name',
            'title',
            'label'
        ]
    );

    $priority = firstNonEmptyValue(
        $item,
        [
            'priority',
            'Priority',
            'checkpoint_priority'
        ]
    );

    $section = firstNonEmptyValue(
        $item,
        [
            'section',
            'Section',
            'section_name',
            'group',
            'category'
        ]
    );

    if (
        isset($item['checkpoint_metadata'])
        && is_array($item['checkpoint_metadata'])
    ) {
        if ($priority === '') {
            $priority = firstNonEmptyValue(
                $item['checkpoint_metadata'],
                ['priority', 'Priority', 'checkpoint_priority']
            );
        }

        if ($section === '') {
            $section = firstNonEmptyValue(
                $item['checkpoint_metadata'],
                ['section', 'Section', 'section_name', 'group', 'category']
            );
        }

        if ($name === '') {
            $name = firstNonEmptyValue(
                $item['checkpoint_metadata'],
                [
                    'checkpoint_name',
                    'checkpoint',
                    'description',
                    'engineering_checkpoint',
                    'text',
                    'name',
                    'title',
                    'label'
                ]
            );
        }
    }

    if (
        isset($checklist['checkpoint_metadata'])
        && is_array($checklist['checkpoint_metadata'])
        && isset($checklist['checkpoint_metadata'][$ref])
        && is_array($checklist['checkpoint_metadata'][$ref])
    ) {
        $savedMeta = $checklist['checkpoint_metadata'][$ref];

        if ($priority === '') {
            $priority = firstNonEmptyValue(
                $savedMeta,
                ['priority', 'Priority', 'checkpoint_priority']
            );
        }

        if ($section === '') {
            $section = firstNonEmptyValue(
                $savedMeta,
                ['section', 'Section', 'section_name', 'group', 'category']
            );
        }

        if ($name === '') {
            $name = firstNonEmptyValue(
                $savedMeta,
                [
                    'checkpoint_name',
                    'checkpoint',
                    'description',
                    'engineering_checkpoint',
                    'text',
                    'name',
                    'title',
                    'label'
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

function evidenceValue(
    array $item,
    string $type,
    array $databaseEvidence,
    string $ref
): string {
    $aliases = match ($type) {
        'photo' => ['photo', 'photo_path', 'photo_url'],
        'video' => ['video', 'video_path', 'video_url'],
        'document' => ['document', 'document_path', 'document_url'],
        default => [$type]
    };

    foreach ($aliases as $key) {
        if (
            isset($item[$key])
            && is_scalar($item[$key])
        ) {
            $value = trim((string)$item[$key]);

            if ($value !== '') {
                return $value;
            }
        }
    }

    if (
        isset($item['evidence'])
        && is_array($item['evidence'])
    ) {
        foreach ($aliases as $key) {
            if (
                isset($item['evidence'][$key])
                && is_scalar($item['evidence'][$key])
            ) {
                $value = trim((string)$item['evidence'][$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }
    }

    if (
        isset($databaseEvidence[$ref])
        && !empty($databaseEvidence[$ref][$type])
    ) {
        return trim(
            (string)$databaseEvidence[$ref][$type]
        );
    }

    return '';
}

/* =========================================================
   ADMIN READING + SAT MATRIX METADATA HELPERS
   ---------------------------------------------------------
   The Admin page must display the actual reading recorded by
   the Inspector wherever the submitted checklist contains one.
   SAT matrix rows are stored under checklist['matrices'] and
   therefore need a separate display pass.
========================================================= */

function adminActualReading(array $item): string
{
    $keys = [
        'actual_reading',
        'actualReading',
        'reading',
        'actual_value',
        'actualValue',
        'measured_value',
        'measuredValue',
        'measured',
        'value'
    ];

    foreach ($keys as $key) {
        if (
            array_key_exists($key, $item)
            && is_scalar($item[$key])
        ) {
            $value = trim((string)$item[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    foreach (['reading_data', 'measurement', 'reading'] as $parent) {
        if (
            isset($item[$parent])
            && is_array($item[$parent])
        ) {
            foreach ([
                'actual_reading',
                'actualReading',
                'reading',
                'value',
                'measured_value'
            ] as $key) {
                if (
                    array_key_exists($key, $item[$parent])
                    && is_scalar($item[$parent][$key])
                ) {
                    $value = trim((string)$item[$parent][$key]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }
    }

    return '';
}

function satMatrixMetaForAdmin(
    string $ref,
    array $item
): array {
    $parts = explode('|', $ref);
    $section = strtoupper(trim((string)($parts[0] ?? '')));
    $parameter = trim((string)($parts[1] ?? ''));
    $condition = trim((string)($parts[2] ?? ''));

    $parameterLabels = [
        'up_current' => 'UP Current',
        'down_current' => 'DOWN Current',
        'voltage' => 'Voltage',
        'starting_current' => 'Starting Current',
        'running_current' => 'Running Current',
        'leveling_accuracy' => 'Leveling Accuracy',
        'safety_gear' => 'Safety Gear Test',
        'buffer_compression' => 'Buffer Compression Test',
        'working' => 'Working',
        'ard' => 'Rescue Successful',
    ];

    $name = '';

    if (
        isset($item['checkpoint_name'])
        && is_scalar($item['checkpoint_name'])
    ) {
        $name = trim((string)$item['checkpoint_name']);
    }

    if ($name === '') {
        foreach (['parameter_name', 'parameter', 'label', 'name', 'text', 'description'] as $key) {
            if (
                isset($item[$key])
                && is_scalar($item[$key])
                && trim((string)$item[$key]) !== ''
            ) {
                $name = trim((string)$item[$key]);
                break;
            }
        }
    }

    if ($name === '' && isset($parameterLabels[$parameter])) {
        $name = $parameterLabels[$parameter];
    }

    if ($name === '') {
        $name = $parameter !== '' ? $parameter : 'SAT Matrix Checkpoint';
    }

    $priority = '—';

    if (
        isset($item['priority'])
        && is_scalar($item['priority'])
        && trim((string)$item['priority']) !== ''
    ) {
        $priority = trim((string)$item['priority']);
    } elseif ($section === 'E' && in_array($parameter, ['safety_gear', 'buffer_compression'], true)) {
        $priority = 'P1';
    } elseif (in_array($section, ['A', 'G', 'H', 'I'], true)) {
        $priority = 'P1';
    }

    $sectionNames = [
        'A' => 'A — Load Test & Running Parameters',
        'E' => 'E — Safety Device Performance Test',
        'G' => 'G — Manual Rescue Operation',
        'H' => 'H — Battery Rescue Operation',
        'I' => 'I — Automatic Rescue Device (ARD)',
    ];

    $sectionName = $sectionNames[$section] ?? (
        $section !== '' ? $section : 'SAT Matrix'
    );

    return [
        'name' => $name,
        'priority' => $priority,
        'section' => $sectionName,
        'condition' => $condition
    ];
}

/* =========================================================
   AUTOMATIC COMPLIANCE ENGINE
========================================================= */

function calculateCompliance(
    array $results
): array {

    $c = 0;
    $nc = 0;
    $na = 0;
    $ca = 0;
    $caPending = 0;
    $caApproved = 0;
    $caRejected = 0;

    foreach ($results as $ref => $item) {

        if (!is_array($item)) {
            continue;
        }

        $result = strtoupper(trim((string)(
            $item['result'] ?? ''
        )));

        /*
         * CA is not silently treated as C.
         * Owner permission is the controlling decision:
         *
         * approved -> counts as C
         * rejected -> counts as NC
         * pending  -> remains CA and blocks certificate
         */
        if ($result === 'CA') {

            $decision =
                $item['owner_ca_decision']
                ?? [];

            $decisionStatus = is_array($decision)
                ? strtolower(trim((string)(
                    $decision['status'] ?? ''
                )))
                : '';

            if ($decisionStatus === 'approved') {
                $c++;
                $caApproved++;
            } elseif ($decisionStatus === 'rejected') {
                $nc++;
                $caRejected++;
            } else {
                $ca++;
                $caPending++;
            }

            continue;
        }

        if ($result === 'OK' || $result === 'C') {
            $c++;
        } elseif ($result === 'NC') {
            $nc++;
        } elseif ($result === 'NA') {
            $na++;
        }
    }

    $denominator = $c + $nc;

    $compliance = $denominator > 0
        ? round(($c / $denominator) * 100, 2)
        : 0.0;

    if ($compliance >= 95) {
        $category = 'Excellent';
    } elseif ($compliance >= 85) {
        $category = 'Good';
    } elseif ($compliance >= 70) {
        $category = 'Fair';
    } else {
        $category = 'Improvement Required';
    }

    if ($compliance >= 95) {
        $stars = '★★★★★';
        $starValue = 5;
    } elseif ($compliance >= 90) {
        $stars = '★★★★☆';
        $starValue = 4;
    } elseif ($compliance >= 80) {
        $stars = '★★★☆☆';
        $starValue = 3;
    } elseif ($compliance >= 70) {
        $stars = '★★☆☆☆';
        $starValue = 2;
    } else {
        $stars = '★☆☆☆☆';
        $starValue = 1;
    }

    /*
     * Only unresolved CA blocks final certification.
     */
    $pending = ($caPending > 0);

    return [
        'C' => $c,
        'NC' => $nc,
        'NA' => $na,
        'CA' => $ca,
        'CA_APPROVED' => $caApproved,
        'CA_REJECTED' => $caRejected,
        'CA_PENDING' => $caPending,
        'denominator' => $denominator,
        'compliance' => $compliance,
        'category' => $category,
        'stars' => $stars,
        'star_value' => $starValue,
        'pending' => $pending,
    ];
}


/* =========================================================
   OWNER ONE-TIME RESPONSIBILITY CONFIRMATION
   ---------------------------------------------------------
   Owner submits ONE confirmation for all NC + CA checkpoints,
   together with ONE supporting compliance/rectification document.

   The confirmation is stored inside maintenance_jobs.checklist
   JSON. No separate SQL column is required.
========================================================= */

function findOwnerResponsibilityConfirmation(array $checklist): array
{
    $candidateKeys = [
        'owner_responsibility_confirmation',
        'ownerResponsibilityConfirmation',
        'owner_confirmation',
        'owner_compliance_confirmation',
        'owner_compliance',
        'owner_rectification_confirmation',
        'owner_nc_ca_confirmation'
    ];

    foreach ($candidateKeys as $key) {
        if (isset($checklist[$key]) && is_array($checklist[$key])) {
            return $checklist[$key];
        }
    }

    /*
     * Also support a nested owner section if the save handler
     * stores the confirmation there.
     */
    foreach (['owner', 'owner_action', 'owner_actions', 'compliance'] as $parent) {
        if (!isset($checklist[$parent]) || !is_array($checklist[$parent])) {
            continue;
        }

        foreach ($candidateKeys as $key) {
            if (isset($checklist[$parent][$key]) && is_array($checklist[$parent][$key])) {
                return $checklist[$parent][$key];
            }
        }

        /*
         * Direct nested confirmation object.
         */
        $nested = $checklist[$parent];

        $hasConfirmation =
            isset($nested['confirmed']) ||
            isset($nested['responsibility_confirmed']) ||
            isset($nested['owner_responsibility_confirmed']) ||
            isset($nested['supporting_document']) ||
            isset($nested['document']);

        if ($hasConfirmation) {
            return $nested;
        }
    }

    /*
     * Last-resort compatibility: if the confirmation fields were
     * stored directly in the checklist root.
     */
    $direct = [];

    foreach ([
        'confirmed',
        'responsibility_confirmed',
        'owner_responsibility_confirmed',
        'confirmation_status',
        'status',
        'confirmed_at',
        'confirmed_at_ist',
        'submitted_at',
        'submitted_at_ist',
        'owner_id',
        'owner_name',
        'supporting_document',
        'supporting_document_path',
        'document',
        'document_path',
        'file',
        'file_path'
    ] as $key) {
        if (array_key_exists($key, $checklist)) {
            $direct[$key] = $checklist[$key];
        }
    }

    return $direct;
}

function ownerConfirmationIsConfirmed(array $confirmation): bool
{
    foreach ([
        'confirmed',
        'responsibility_confirmed',
        'owner_responsibility_confirmed',
        'is_confirmed'
    ] as $key) {
        if (array_key_exists($key, $confirmation)) {
            $value = $confirmation[$key];

            if ($value === true || $value === 1 || $value === '1') {
                return true;
            }

            if (is_string($value) && in_array(
                strtolower(trim($value)),
                ['yes', 'true', 'confirmed', 'approved', 'submitted'],
                true
            )) {
                return true;
            }
        }
    }

    $status = strtolower(trim((string)(
        $confirmation['confirmation_status']
        ?? $confirmation['status']
        ?? ''
    )));

    return in_array(
        $status,
        ['confirmed', 'approved', 'submitted', 'completed'],
        true
    );
}

function ownerConfirmationDocument(array $confirmation): string
{
    foreach ([
        'supporting_document',
        'supporting_document_path',
        'document',
        'document_path',
        'document_url',
        'file',
        'file_path',
        'file_url'
    ] as $key) {
        if (
            isset($confirmation[$key])
            && is_scalar($confirmation[$key])
            && trim((string)$confirmation[$key]) !== ''
        ) {
            return trim((string)$confirmation[$key]);
        }
    }

    if (
        isset($confirmation['supporting_document'])
        && is_array($confirmation['supporting_document'])
    ) {
        foreach (['path', 'file', 'url', 'stored_path'] as $key) {
            if (
                isset($confirmation['supporting_document'][$key])
                && is_scalar($confirmation['supporting_document'][$key])
                && trim((string)$confirmation['supporting_document'][$key]) !== ''
            ) {
                return trim((string)$confirmation['supporting_document'][$key]);
            }
        }
    }

    return '';
}

function ownerConfirmationValue(array $confirmation, array $keys): string
{
    foreach ($keys as $key) {
        if (
            isset($confirmation[$key])
            && is_scalar($confirmation[$key])
            && trim((string)$confirmation[$key]) !== ''
        ) {
            return trim((string)$confirmation[$key]);
        }
    }

    return '';
}

/* =========================================================
   ADMIN / JOB
========================================================= */

$adminId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($adminId <= 0) {
    header('Location: ../login.php');
    exit;
}

$jobId = (int)(
    $_GET['job_id']
    ?? $_POST['job_id']
    ?? 0
);

if ($jobId <= 0) {
    header('Location: checklist-reviews.php');
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
        mj.job_type,
        mj.created_at,
        mj.updated_at,
        mj.owner_remark,

        mj.checklist,

        mj.admin_rating,
        mj.admin_review_remark,
        mj.admin_review_status,
        mj.admin_reviewed_at,

        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.proposed_inspection_date,
        s.owner_accepted_at,
        s.status AS saf_status,

        owner.name AS owner_name,
        owner.email AS owner_email,

        tech.name AS technician_name,
        tech.email AS technician_email,

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

    LEFT JOIN assets a
        ON a.id = mj.asset_id

    WHERE
        mj.id = ?
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
    'i',
    $jobId
);

if (!$stmt->execute()) {
    die(
        'Unable to load inspection: '
        . h($stmt->error)
    );
}

$job =
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();

if (!$job) {
    die('Inspection record not found.');
}


/* =========================================================
   R01 INSPECTION REPORT
   ---------------------------------------------------------
   The generated R01 report is stored in report_requests.
   Load the latest report request for this exact inspection job.
   No report data is copied into maintenance_jobs.
========================================================= */

$reportRequest = null;
$reportTableExists = false;

$reportTableCheck = $conn->query(
    "SHOW TABLES LIKE 'report_requests'"
);

if (
    $reportTableCheck
    &&
    $reportTableCheck->num_rows > 0
) {
    $reportTableExists = true;

    /*
     * rr.* is intentionally used so this Admin page remains
     * compatible with installations that have optional columns
     * such as report_number, report_file, generated_at, etc.
     */
    $reportStmt = $conn->prepare("
        SELECT rr.*
        FROM report_requests rr
        WHERE rr.job_id = ?
        ORDER BY rr.id DESC
        LIMIT 1
    ");

    if ($reportStmt) {
        $reportStmt->bind_param('i', $jobId);

        if ($reportStmt->execute()) {
            $reportRequest =
                $reportStmt
                    ->get_result()
                    ->fetch_assoc();
        }

        $reportStmt->close();
    }
}

/*
 * Resolve the stored PDF path from whichever report column is
 * available in this installation.
 */
$adminReportPath = '';

if (is_array($reportRequest)) {
    foreach ([
        'report_path',
        'report_file',
        'file_path',
        'pdf_path'
    ] as $reportPathKey) {
        if (
            isset($reportRequest[$reportPathKey])
            &&
            is_scalar($reportRequest[$reportPathKey])
        ) {
            $candidateReportPath =
                trim((string)$reportRequest[$reportPathKey]);

            if ($candidateReportPath !== '') {
                $adminReportPath = $candidateReportPath;
                break;
            }
        }
    }
}

$adminReportStatus = strtolower(trim((string)(
    $reportRequest['status'] ?? ''
)));

$adminReportNumber = trim((string)(
    $reportRequest['report_number'] ?? ''
));

$adminReportRequestedAt = trim((string)(
    $reportRequest['requested_at']
    ?? ''
));

$adminReportGeneratedAt = trim((string)(
    $reportRequest['generated_at']
    ?? ''
));

$adminReportSentAt = trim((string)(
    $reportRequest['sent_at']
    ?? ''
));


/* =========================================================
   DISPLAY VARIABLES
   ---------------------------------------------------------
   These values are used by the Inspection Information card.
   Keep them initialized here so the editor/PHP runtime never
   sees them as undefined.
========================================================= */

$displayAsset =
    trim((string)($job['display_asset_id'] ?? ''));

if ($displayAsset === '') {
    $displayAsset =
        trim((string)($job['asset_name'] ?? ''));
}

if ($displayAsset === '') {
    $displayAsset = '—';
}

$inspectionDateText = '—';

$inspectionDateValue =
    trim((string)($job['proposed_inspection_date'] ?? ''));

if ($inspectionDateValue !== '') {
    try {
        $inspectionDate = new DateTime(
            $inspectionDateValue,
            new DateTimeZone('Asia/Kolkata')
        );

        $inspectionDateText =
            $inspectionDate->format('d / m / Y');
    } catch (Throwable $e) {
        $inspectionDateText = '—';
    }
}


/* =========================================================
   DECODE CHECKLIST
========================================================= */

$checklist = [];

if (!empty($job['checklist'])) {

    $decoded =
        json_decode(
            $job['checklist'],
            true
        );

    if (is_array($decoded)) {
        $checklist = $decoded;
    }
}

$results = is_array(
    $checklist['results'] ?? null
)
    ? $checklist['results']
    : [];

$sectionRemarks = is_array(
    $checklist['section_remarks'] ?? null
)
    ? $checklist['section_remarks']
    : [];


/* =========================================================
   CHECKLIST EVIDENCE FROM DATABASE
   ---------------------------------------------------------
   Fallback for photo/video/document stored in
   saf_checklist_evidence. Checklist JSON remains authoritative
   for result/remark; this only supplies evidence paths.
========================================================= */

$databaseEvidence = [];

$evidenceTableCheck = $conn->query("
    SHOW TABLES LIKE 'saf_checklist_evidence'
");

if (
    $evidenceTableCheck
    && $evidenceTableCheck->num_rows > 0
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
        $evidenceStmt->bind_param('i', $jobId);

        if ($evidenceStmt->execute()) {
            $evidenceResult = $evidenceStmt->get_result();

            while ($evidenceRow = $evidenceResult->fetch_assoc()) {
                $refCode = trim((string)($evidenceRow['ref_code'] ?? ''));

                if ($refCode === '') {
                    continue;
                }

                if (!isset($databaseEvidence[$refCode])) {
                    $databaseEvidence[$refCode] = [
                        'photo' => '',
                        'video' => '',
                        'document' => ''
                    ];
                }

                foreach ([
                    'photo' => 'photo_path',
                    'video' => 'video_path',
                    'document' => 'document_path'
                ] as $key => $column) {
                    $path = trim((string)($evidenceRow[$column] ?? ''));
                    if ($path !== '') {
                        $databaseEvidence[$refCode][$key] = $path;
                    }
                }
            }
        }

        $evidenceStmt->close();
    }
}

$ownerApproval = is_array(
    $checklist['owner_approval'] ?? null
)
    ? $checklist['owner_approval']
    : [];

$finalStatus = (string)(
    $checklist['final_status']
    ?? '—'
);

/*
 * Owner approval is actually recorded in maintenance_jobs:
 *   status       = approved / rejected / pending_owner
 *   owner_remark = Owner's submitted remark
 *
 * The JSON owner_approval object is kept as a compatibility
 * fallback for older inspection records.
 */
$dbJobStatus = strtolower(trim((string)(
    $job['job_status'] ?? ''
)));

$jsonOwnerApprovalStatus = strtolower(trim((string)(
    $ownerApproval['status'] ?? ''
)));

$ownerApprovalStatus = $jsonOwnerApprovalStatus !== ''
    ? $jsonOwnerApprovalStatus
    : (
        $dbJobStatus === 'approved'
            ? 'approved'
            : (
                $dbJobStatus === 'rejected'
                    ? 'rejected'
                    : (
                        $dbJobStatus === 'pending_owner'
                            ? 'pending'
                            : ''
                    )
            )
    );

/* Owner remark is stored directly in maintenance_jobs.owner_remark. */
$ownerReason = trim((string)(
    $job['owner_remark'] ?? ''
));

if ($ownerReason === '') {
    $ownerReason = trim((string)(
        $ownerApproval['reason'] ?? ''
    ));
}

/*
 * Current schema has no dedicated owner approval timestamp.
 * Use JSON timestamp when available; otherwise maintenance_jobs.updated_at.
 */
$ownerApprovedAt = trim((string)(
    $ownerApproval['approved_at']
    ?? $ownerApproval['approved_at_ist']
    ?? ''
));

$ownerApprovedAtIsExplicitIst =
    !empty($ownerApproval['approved_at_ist']);

if (
    $ownerApprovedAt === ''
    &&
    $ownerApprovalStatus === 'approved'
) {
    $ownerApprovedAt = trim((string)(
        $job['updated_at'] ?? ''
    ));
    $ownerApprovedAtIsExplicitIst = false;
}


/* =========================================================
   FLOOR-WISE CHECKLIST — D2
   ---------------------------------------------------------
   Current PAT checklist has:
   - PAT-001 -> PAT-131 fixed checkpoints
   - D2 dynamic floor-wise execution checkpoints
   - C / NC / NA / CA
========================================================= */

$floorChecklist = [];

foreach (['floor_checklist', 'floor_results', 'floor_matrix'] as $container) {

    if (
        empty($checklist[$container])
        ||
        !is_array($checklist[$container])
    ) {
        continue;
    }

    foreach ($checklist[$container] as $floor => $floorItems) {

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


/* =========================================================
   DATABASE EVIDENCE
   ---------------------------------------------------------
   NC photograph is mandatory in the Inspector checklist.
   Evidence may be stored in saf_checklist_evidence rather
   than only inside checklist JSON.
========================================================= */

$databaseEvidence = [];

$evidenceTableCheck = $conn->query(
    "SHOW TABLES LIKE 'saf_checklist_evidence'"
);

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

            while ($ev = $evidenceResult->fetch_assoc()) {

                $ref = trim((string)(
                    $ev['ref_code'] ?? ''
                ));

                if ($ref === '') {
                    continue;
                }

                if (!isset($databaseEvidence[$ref])) {
                    $databaseEvidence[$ref] = [
                        'photo' => '',
                        'video' => '',
                        'document' => ''
                    ];
                }

                foreach ([
                    'photo' => 'photo_path',
                    'video' => 'video_path',
                    'document' => 'document_path'
                ] as $target => $source) {

                    $value = trim((string)(
                        $ev[$source] ?? ''
                    ));

                    if ($value !== '') {
                        $databaseEvidence[$ref][$target] = $value;
                    }
                }
            }
        }

        $evidenceStmt->close();
    }
}


/* =========================================================
   MERGE DATABASE EVIDENCE
========================================================= */

foreach ($databaseEvidence as $ref => $ev) {

    if (isset($results[$ref]) && is_array($results[$ref])) {

        foreach (['photo', 'video', 'document'] as $key) {

            if (
                empty($results[$ref][$key])
                &&
                !empty($ev[$key])
            ) {
                $results[$ref][$key] = $ev[$key];
            }
        }
    }

    foreach ($floorChecklist as $floor => $items) {

        if (!isset($items[$ref])) {
            continue;
        }

        foreach (['photo', 'video', 'document'] as $key) {

            if (
                empty($floorChecklist[$floor][$ref][$key])
                &&
                !empty($ev[$key])
            ) {
                $floorChecklist[$floor][$ref][$key] =
                    $ev[$key];
            }
        }
    }
}


/* =========================================================
   FINAL SCORING DATA
   ---------------------------------------------------------
   D2 floor checkpoints are part of the execution checklist,
   so they must also be included in Admin's final score.
========================================================= */

$scoringResults = $results;

foreach ($floorChecklist as $floor => $floorItems) {

    foreach ($floorItems as $checkpoint => $item) {

        if (!is_array($item)) {
            continue;
        }

        $scoringKey =
            'FLOOR-' .
            $floor .
            '-' .
            $checkpoint;

        $scoringResults[$scoringKey] = $item;
    }
}

/*
 * SAT load/safety/rescue matrix execution rows are stored
 * separately from fixed results. Include them in the Admin
 * score/count so every submitted SAT execution checkpoint is
 * represented.
 */
if (
    isset($checklist['matrices'])
    && is_array($checklist['matrices'])
) {
    foreach ($checklist['matrices'] as $matrixRef => $matrixItem) {
        if (!is_array($matrixItem)) {
            continue;
        }

        $matrixResult = strtoupper(trim((string)($matrixItem['result'] ?? '')));

        if (in_array($matrixResult, ['C', 'OK', 'NC', 'NA', 'CA'], true)) {
            $scoringResults['MATRIX|' . (string)$matrixRef] = $matrixItem;
        }
    }
}
/* =========================================================
   CALCULATE FINAL SCORE
========================================================= */

$score = calculateCompliance(
    $scoringResults
);

$compliance = $score['compliance'];
$category = $score['category'];
$stars = $score['stars'];
$starValue = $score['star_value'];

$certificateStatus = strtolower(trim((string)(
    $job['certificate_status'] ?? ''
)));

$certificateFile =
    (string)(
        $checklist['certificate_file']
        ?? ''
    );


/* =========================================================
   CERTIFICATE ACTIONS
   ---------------------------------------------------------
   These actions are intentionally handled on this same
   Admin review page.

   Owner request is represented by:
       certificate_status = requested

   Generate:
       requested -> generated

   Send:
       generated -> sent

   No manual rating is accepted.
========================================================= */

$actionError = '';
$actionSuccess = '';

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['certificate_action'])
) {

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

        $certificateAction =
            strtolower(trim((string)(
                $_POST['certificate_action']
                ?? ''
            )));


        /* =================================================
           GENERATE CERTIFICATE
        ================================================= */

        if (
            $certificateAction ===
            'generate'
        ) {

            if (
                $certificateStatus !==
                'requested'
                &&
                $certificateStatus !==
                'generated'
                &&
                $certificateStatus !==
                'sent'
            ) {

                $actionError =
                    'Certificate has not been requested by the Owner yet.';

            } elseif ($score['pending']) {

                $actionError =
                    'Certificate cannot be generated while '
                    . $score['CA_PENDING']
                    . ' CA checkpoint(s) are still awaiting Owner approval.';

            } elseif (
                $score['denominator'] <= 0
            ) {

                $actionError =
                    'Certificate cannot be generated because there are no C/NC checkpoints to score.';

            } else {

                /*
                 * Locate Dompdf.
                 */
                $autoloadCandidates = [

                    __DIR__ . '/../vendor/autoload.php',

                    __DIR__ . '/../../vendor/autoload.php',

                    __DIR__ . '/../dompdf/autoload.inc.php',

                    __DIR__ . '/../../dompdf/autoload.inc.php',

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
                    ||
                    !class_exists('Dompdf\\Dompdf')
                ) {

                    $actionError =
                        'Dompdf is not installed. Please install Dompdf before generating the certificate.';

                } else {

                    // Always initialize before entering try so the catch block
                    // is safe even if an exception occurs before begin_transaction().
                    $transactionStarted = false;

                    try {

                        $certificateDir =
                            __DIR__
                            . '/reports/certificates';

                        if (
                            !is_dir(
                                $certificateDir
                            )
                        ) {

                            if (
                                !mkdir(
                                    $certificateDir,
                                    0775,
                                    true
                                )
                                &&
                                !is_dir(
                                    $certificateDir
                                )
                            ) {

                                throw new RuntimeException(
                                    'Unable to create certificate directory.'
                                );
                            }
                        }


                        $certificateNo =
                            trim(
                                (string)(
                                    $job['certificate_no']
                                    ?? ''
                                )
                            );

                        if (
                            $certificateNo === ''
                        ) {

                            $certificateNo =
                                'NLETA-PAT-CERT-'
                                .
                                date('Y')
                                .
                                '-'
                                .
                                str_pad(
                                    (string)$jobId,
                                    5,
                                    '0',
                                    STR_PAD_LEFT
                                );
                        }


                        $safeCertificateNo =
                            preg_replace(
                                '/[^A-Za-z0-9_-]/',
                                '-',
                                $certificateNo
                            );


                        $fileName =
                            $safeCertificateNo
                            .
                            '.pdf';


                        $pdfPath =
                            $certificateDir
                            .
                            '/'
                            .
                            $fileName;


                        $relativePath =
                            'maintenance/reports/certificates/'
                            .
                            $fileName;


                        $inspectionDate =
                            dateOnly(
                                $job['proposed_inspection_date']
                                ??
                                $job['created_at']
                                ??
                                null
                            );


                        $generatedAt =
                            date(
                                'd / m / Y h:i A'
                            )
                            .
                            ' IST';


                        $clientName =
                            $job['owner_name']
                            ?:
                            '—';

                        $lift =
                            $job['display_asset_id']
                            ?:
                            $job['asset_name']
                            ?:
                            '—';

                        $location =
                            $job['location']
                            ?:
                            '—';

                        $psga =
                            $job['psga_no']
                            ?:
                            '—';

                        $saf =
                            $job['saf_no']
                            ?:
                            '—';


                        $html = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<title>NLETA PAT Certificate</title>

<style>

@page {
    size: A4 portrait;
    margin: 15mm;
}

body {
    font-family: DejaVu Sans, sans-serif;
    color: #111;
    font-size: 10px;
    margin: 0;
}

.header {
    text-align: center;
    border-bottom: 2px solid #111;
    padding-bottom: 9px;
}

.agency {
    font-size: 17px;
    font-weight: bold;
}

.sub {
    font-size: 9px;
    margin-top: 3px;
}

.title {
    text-align: center;
    font-size: 17px;
    font-weight: bold;
    margin: 18px 0 12px;
}

.subtitle {
    text-align: center;
    font-size: 10px;
    margin-bottom: 18px;
}

.box {
    border: 1px solid #222;
    margin-bottom: 12px;
}

.box-title {
    background: #eeeeee;
    border-bottom: 1px solid #222;
    padding: 6px 8px;
    font-weight: bold;
}

.info {
    width: 100%;
    border-collapse: collapse;
}

.info td {
    border-bottom: 1px solid #ddd;
    padding: 6px 8px;
}

.label {
    width: 28%;
    font-weight: bold;
}

.score {
    width: 100%;
    border-collapse: collapse;
    margin-top: 2px;
}

.score th,
.score td {
    border: 1px solid #222;
    padding: 7px;
    text-align: center;
}

.score th {
    background: #eeeeee;
}

.big-score {
    text-align: center;
    font-size: 26px;
    font-weight: bold;
    padding: 12px;
}

.category {
    text-align: center;
    font-size: 16px;
    font-weight: bold;
    padding: 8px;
}

.stars {
    text-align: center;
    font-size: 22px;
    padding: 8px;
}

.declaration {
    line-height: 1.5;
    padding: 10px;
}

.signature {
    width: 100%;
    border-collapse: collapse;
    margin-top: 45px;
}

.signature td {
    width: 33.33%;
    text-align: center;
    padding-top: 28px;
}

.line {
    border-top: 1px solid #222;
    margin: 0 12px 5px;
}

.footer {
    position: fixed;
    bottom: -5mm;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8px;
}

</style>

</head>

<body>

<div class="header">

    <div class="agency">
        NATIONAL LIFT ESCALATOR TESTING AGENCY
    </div>

    <div class="sub">
        NLETA ARCHIVE DOCUMENTATION
    </div>

    <div class="sub">
        Controlled Engineering Certificate
        | Revision 1 (Rev.1)
    </div>

</div>


<div class="title">
    PAT INSPECTION CERTIFICATE
</div>

<div class="subtitle">
    Certificate generated from completed CHK-01
    PAT Master Checklist
</div>


<div class="box">

    <div class="box-title">
        Certificate & Inspection Details
    </div>

    <table class="info">

        <tr>
            <td class="label">Certificate No.</td>
            <td>' . h($certificateNo) . '</td>
        </tr>

        <tr>
            <td class="label">Owner / Client</td>
            <td>' . h($clientName) . '</td>
        </tr>

        <tr>
            <td class="label">Lift / Asset</td>
            <td>' . h($lift) . '</td>
        </tr>

        <tr>
            <td class="label">Location</td>
            <td>' . h($location) . '</td>
        </tr>

        <tr>
            <td class="label">PSGA No.</td>
            <td>' . h($psga) . '</td>
        </tr>

        <tr>
            <td class="label">SAF No.</td>
            <td>' . h($saf) . '</td>
        </tr>

        <tr>
            <td class="label">Inspection Date</td>
            <td>' . h($inspectionDate) . '</td>
        </tr>

        <tr>
            <td class="label">Certificate Generated</td>
            <td>' . h($generatedAt) . '</td>
        </tr>

    </table>

</div>


<div class="box">

    <div class="box-title">
        Compliance Assessment
    </div>

    <div class="big-score">
        ' . h(number_format($compliance, 2)) . '%
    </div>

    <div class="category">
        Indicative Category: ' . h($category) . '
    </div>

    <div class="stars">
        ' . h($stars) . '
    </div>

</div>


<div class="box">

    <div class="box-title">
        Score Summary
    </div>

    <table class="score">

        <thead>

            <tr>
                <th>Result</th>
                <th>Count</th>
                <th>Weight</th>
            </tr>

        </thead>

        <tbody>

            <tr>
                <td>C</td>
                <td>' . (int)$score['C'] . '</td>
                <td>100%</td>
            </tr>

            <tr>
                <td>NC</td>
                <td>' . (int)$score['NC'] . '</td>
                <td>0%</td>
            </tr>

            <tr>
                <td>NA</td>
                <td>' . (int)$score['NA'] . '</td>
                <td>Excluded</td>
            </tr>


        </tbody>

    </table>

</div>


<div class="box">

    <div class="box-title">
        Certificate Declaration
    </div>

    <div class="declaration">

        This certificate is generated from the completed
        NLETA CHK-01 Rev.1 Partial Acceptance Test
        checklist. The compliance score is calculated as:

        <br><br>

        <strong>
            C ÷ (C + NC) × 100
        </strong>

        <br><br>

        Not Applicable (NA) checkpoints are excluded from
        the calculation. Conditional Acceptance (CA) checkpoints
        require Owner approval. An unresolved CA checkpoint must
        not remain pending at the time of certificate generation.

        <br><br>

        The star rating shown on this certificate is an
        automatically calculated Admin Certificate rating.
        It is not exposed in the client R01 report.

    </div>

</div>


<table class="signature">

<tr>

<td>
    <div class="line"></div>
    Inspector
</td>

<td>
    <div class="line"></div>
    NLETA
</td>

<td>
    <div class="line"></div>
    Admin Certification
</td>

</tr>

</table>


<div class="footer">
    NLETA PAT Certificate |
    ' . h($certificateNo) . ' |
    Generated in IST |
    Rev.1
</div>


</body>

</html>
';


                        $options =
                            new \Dompdf\Options();

                        $options->set(
                            'isRemoteEnabled',
                            true
                        );

                        $options->set(
                            'isHtml5ParserEnabled',
                            true
                        );

                        $options->set(
                            'defaultFont',
                            'DejaVu Sans'
                        );


                        $dompdf =
                            new \Dompdf\Dompdf(
                                $options
                            );


                        $dompdf->loadHtml(
                            $html
                        );

                        $dompdf->setPaper(
                            'A4',
                            'portrait'
                        );

                        $dompdf->render();

                        $pdfOutput =
                            $dompdf->output();


                        if (
                            empty($pdfOutput)
                        ) {

                            throw new RuntimeException(
                                'Certificate PDF output is empty.'
                            );
                        }


                        $written =
                            file_put_contents(
                                $pdfPath,
                                $pdfOutput,
                                LOCK_EX
                            );


                        if (
                            $written === false
                            ||
                            $written <= 0
                        ) {

                            throw new RuntimeException(
                                'Unable to save certificate PDF.'
                            );
                        }


                        /*
                         * Save certificate metadata inside
                         * the existing checklist JSON so no
                         * new database column is required.
                         */

                        $updatedChecklist =
                            $checklist;

                        $updatedChecklist[
                            'certificate'
                        ] = [

                            'certificate_no' =>
                                $certificateNo,

                            'certificate_file' =>
                                $relativePath,

                            'compliance' =>
                                $compliance,

                            'category' =>
                                $category,

                            'stars' =>
                                $stars,

                            'star_value' =>
                                $starValue,

                            'C' =>
                                $score['C'],

                            'NC' =>
                                $score['NC'],

                            'NA' =>
                                $score['NA'],

                            'formula' =>
                                'C / (C + NC) * 100',

                            'timezone' =>
                                'Asia/Kolkata',

                            'generated_at_ist' =>
                                date(
                                    'Y-m-d H:i:s'
                                )

                        ];

                        $updatedChecklist[
                            'certificate_file'
                        ] =
                            $relativePath;


                        $json =
                            json_encode(
                                $updatedChecklist,
                                JSON_UNESCAPED_UNICODE
                                |
                                JSON_UNESCAPED_SLASHES
                                |
                                JSON_PRETTY_PRINT
                            );


                        if (
                            $json === false
                        ) {

                            throw new RuntimeException(
                                'Unable to save certificate metadata.'
                            );
                        }


                        $transactionStarted = false;
                        $conn->begin_transaction();
                        $transactionStarted = true;


                        $update =
                            $conn->prepare("
                                UPDATE maintenance_jobs

                                SET
                                    checklist = ?,
                                    certificate_status = 'generated',
                                    certificate_no = ?,
                                    certificate_generated_at = NOW(),
                                    updated_at = NOW()

                                WHERE
                                    id = ?

                                LIMIT 1
                            ");


                        if (!$update) {

                            throw new RuntimeException(
                                'Unable to prepare certificate update: '
                                .
                                $conn->error
                            );
                        }


                        $update->bind_param(
                            'ssi',
                            $json,
                            $certificateNo,
                            $jobId
                        );


                        if (
                            !$update->execute()
                        ) {

                            throw new RuntimeException(
                                'Unable to save generated certificate: '
                                .
                                $update->error
                            );
                        }


                        $update->close();

                        $conn->commit();
                        $transactionStarted = false;


                        $_SESSION[
                            'admin_certificate_success'
                        ] =
                            'Certificate generated successfully.';


                        header(
                            'Location: admin-checklist-review.php?job_id='
                            .
                            $jobId
                        );

                        exit;

                    } catch (Throwable $e) {

                        if ($transactionStarted) {
                            $conn->rollback();
                            $transactionStarted = false;
                        }

                        $actionError =
                            $e->getMessage();
                    }
                }
            }


        /* =================================================
           SEND CERTIFICATE TO OWNER
        ================================================= */

        } elseif (
            $certificateAction ===
            'send'
        ) {

            if (
                $certificateStatus !==
                'generated'
                &&
                $certificateStatus !==
                'sent'
            ) {

                $actionError =
                    'Generate the certificate before sending it to the Owner.';

            } elseif (
                trim($certificateFile) === ''
            ) {

                $actionError =
                    'Certificate file path is missing. Please generate the certificate again.';

            } else {

                $stored =
                    str_replace(
                        '\\',
                        '/',
                        trim($certificateFile)
                    );

                /*
                 * Certificate paths generated above are:
                 * maintenance/reports/certificates/file.pdf
                 *
                 * From this maintenance page:
                 * __DIR__ = maintenance
                 */
                $candidatePaths = [

                    __DIR__
                    . '/'
                    . preg_replace(
                        '#^maintenance/#i',
                        '',
                        $stored
                    ),

                    dirname(__DIR__)
                    . '/'
                    . ltrim(
                        $stored,
                        '/'
                    ),

                ];

                $certificateExists =
                    false;

                foreach (
                    $candidatePaths
                    as $candidate
                ) {

                    if (
                        is_file($candidate)
                        &&
                        filesize($candidate) > 0
                    ) {

                        $certificateExists =
                            true;

                        break;
                    }
                }


                if (!$certificateExists) {

                    $actionError =
                        'Certificate PDF file could not be found on the server. Please generate it again.';

                } else {

                    $updatedChecklist =
                        $checklist;

                    $updatedChecklist[
                        'certificate'
                    ] = is_array(
                        $updatedChecklist['certificate']
                        ?? null
                    )
                        ? $updatedChecklist['certificate']
                        : [];


                    $updatedChecklist[
                        'certificate'
                    ][
                        'sent_at_ist'
                    ] =
                        date(
                            'Y-m-d H:i:s'
                        );


                    $updatedChecklist[
                        'certificate'
                    ][
                        'delivery_status'
                    ] =
                        'sent_to_owner';


                    $updatedChecklist[
                        'certificate_file'
                    ] =
                        $certificateFile;


                    $json =
                        json_encode(
                            $updatedChecklist,
                            JSON_UNESCAPED_UNICODE
                            |
                            JSON_UNESCAPED_SLASHES
                            |
                            JSON_PRETTY_PRINT
                        );


                    if (
                        $json === false
                    ) {

                        $actionError =
                            'Unable to save certificate delivery information.';

                    } else {

                        $transactionStarted = false;
                        $conn->begin_transaction();
                        $transactionStarted = true;

                        try {

                            /*
                             * The Owner-facing workflow can read
                             * certificate_status = sent and the
                             * certificate_file stored in checklist JSON.
                             */
                            $update =
                                $conn->prepare("
                                    UPDATE maintenance_jobs

                                    SET
                                        checklist = ?,
                                        certificate_status = 'sent',
                                        updated_at = NOW()

                                    WHERE
                                        id = ?

                                    LIMIT 1
                                ");


                            if (!$update) {

                                throw new RuntimeException(
                                    'Unable to prepare certificate delivery update: '
                                    .
                                    $conn->error
                                );
                            }


                            $update->bind_param(
                                'si',
                                $json,
                                $jobId
                            );


                            if (
                                !$update->execute()
                            ) {

                                throw new RuntimeException(
                                    'Unable to send certificate to Owner: '
                                    .
                                    $update->error
                                );
                            }


                            $update->close();

                            $conn->commit();
                            $transactionStarted = false;


                            $_SESSION[
                                'admin_certificate_success'
                            ] =
                                'Certificate sent to Owner successfully.';


                            header(
                                'Location: admin-checklist-review.php?job_id='
                                .
                                $jobId
                            );

                            exit;

                        } catch (Throwable $e) {

                            if ($transactionStarted) {
                                $conn->rollback();
                                $transactionStarted = false;
                            }

                            $actionError =
                                $e->getMessage();
                        }
                    }
                }
            }
        }
    }
}


/* =========================================================
   SESSION MESSAGE
========================================================= */

if (
    !empty(
        $_SESSION[
            'admin_certificate_success'
        ]
    )
) {

    $actionSuccess =
        (string)(
            $_SESSION[
                'admin_certificate_success'
            ]
        );

    unset(
        $_SESSION[
            'admin_certificate_success'
        ]
    );
}


/* =========================================================
   REFRESH CERTIFICATE DATA
========================================================= */

$certificateStatus =
    strtolower(
        trim(
            (string)(
                $job['certificate_status']
                ?? ''
            )
        )
    );


/* =========================================================
   OWNER RESPONSIBILITY CONFIRMATION STATUS
========================================================= */

$ownerResponsibilityConfirmation =
    findOwnerResponsibilityConfirmation($checklist);

$ownerResponsibilityConfirmed =
    ownerConfirmationIsConfirmed(
        $ownerResponsibilityConfirmation
    );

$ownerResponsibilityDocument =
    ownerConfirmationDocument(
        $ownerResponsibilityConfirmation
    );

$ownerResponsibilityStatus =
    ownerConfirmationValue(
        $ownerResponsibilityConfirmation,
        [
            'confirmation_status',
            'status'
        ]
    );

$ownerResponsibilityOwnerName =
    ownerConfirmationValue(
        $ownerResponsibilityConfirmation,
        [
            'owner_name',
            'submitted_by_name',
            'confirmed_by_name'
        ]
    );

$ownerResponsibilityConfirmedAt =
    ownerConfirmationValue(
        $ownerResponsibilityConfirmation,
        [
            'confirmed_at_ist',
            'confirmed_at',
            'submitted_at_ist',
            'submitted_at'
        ]
    );

$ownerResponsibilityOwnerId =
    ownerConfirmationValue(
        $ownerResponsibilityConfirmation,
        [
            'owner_id',
            'submitted_by',
            'confirmed_by'
        ]
    );

/* =========================================================
   CHECKLIST META / COUNTS
   ---------------------------------------------------------
   Total checkpoint count is exactly the number submitted by
   the Inspector: C + NC + CA + NA.

   Only valid submitted results are counted: C + NC + CA + NA.
========================================================= */

$okCount = 0;
$ncCount = 0;
$naCount = 0;
$caCount = 0;

foreach ($scoringResults as $item) {

    if (!is_array($item)) {
        continue;
    }

    $r = strtoupper(trim((string)(
        $item['result'] ?? ''
    )));

    if ($r === 'C' || $r === 'OK') {
        $okCount++;
    } elseif ($r === 'NC') {
        $ncCount++;
    } elseif ($r === 'CA') {
        $caCount++;
    } elseif ($r === 'NA') {
        $naCount++;
    }
}

$totalCount = $okCount + $ncCount + $caCount + $naCount;

/* =========================================================
   BUILD DISPLAY METADATA FROM SAVED INSPECTOR DATA
========================================================= */



$displayMeta = [];

foreach ($results as $ref => $item) {
    if (!is_array($item)) {
        continue;
    }

    $displayMeta[(string)$ref] = checkpointMetaForAdmin(
        (string)$ref,
        $item
    );
}

foreach ($floorChecklist as $floor => $floorItems) {
    foreach ($floorItems as $checkpoint => $item) {
        if (!is_array($item)) {
            continue;
        }

        $displayMeta['FLOOR|' . $floor . '|' . $checkpoint] =
            checkpointMetaForAdmin(
                (string)$checkpoint,
                $item,
                $checklist
            );
    }
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

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

.admin-review-page {
    padding: 24px;
    min-height: calc(100vh - 70px);
}

.review-header,
.review-card {
    background: #fff;
    border: 1px solid #e4e7eb;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(0,0,0,.06);
    margin-bottom: 18px;
    overflow: hidden;
}

.review-header {
    padding: 22px;
}

.review-header h3 {
    margin: 0;
    font-weight: 700;
}

.review-header p {
    margin: 5px 0 0;
    color: #6c757d;
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
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.info-item {
    min-width: 0;
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
    overflow-wrap: anywhere;
}


.owner-confirmation-admin {
    padding: 18px;
}

.owner-confirmation-status-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
}

.owner-confirmation-heading {
    font-size: 16px;
    font-weight: 800;
    color: #172033;
}

.owner-confirmation-text {
    margin-top: 5px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.55;
    max-width: 900px;
}

.owner-confirmation-grid {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
}

.owner-confirmation-item {
    min-width: 0;
    border: 1px solid #e4e8ed;
    border-radius: 9px;
    background: #fafafa;
    padding: 12px;
}

.owner-confirmation-label {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 5px;
}

.owner-confirmation-value {
    min-width: 0;
    font-size: 13px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.owner-confirmation-alert {
    margin-top: 14px;
    border-radius: 8px;
    padding: 11px 13px;
    font-size: 12px;
    line-height: 1.5;
}

.pending-alert {
    background: #fff7df;
    color: #7a5200;
    border: 1px solid #f1d88b;
}

.success-alert {
    background: #eaf8ef;
    color: #0c6b35;
    border: 1px solid #b9e3c8;
}

.r01-report-admin {
    padding: 18px;
}

.r01-report-layout {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 14px;
    align-items: stretch;
}

.r01-report-main,
.r01-report-meta {
    border: 1px solid #e4e8ed;
    border-radius: 10px;
    background: #fafafa;
    padding: 15px;
}

.r01-report-title {
    font-size: 16px;
    font-weight: 800;
    color: #172033;
}

.r01-report-description {
    margin-top: 5px;
    color: #64748b;
    font-size: 12px;
    line-height: 1.55;
}

.r01-report-number {
    margin-top: 12px;
    font-size: 18px;
    font-weight: 800;
}

.r01-report-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
}

.r01-report-meta-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.r01-report-meta-item {
    min-width: 0;
    border: 1px solid #e4e8ed;
    border-radius: 8px;
    background: #fff;
    padding: 10px;
}

.r01-report-meta-label {
    font-size: 10px;
    color: #64748b;
    text-transform: uppercase;
    font-weight: 700;
    margin-bottom: 4px;
}

.r01-report-meta-value {
    font-size: 12px;
    font-weight: 600;
    overflow-wrap: anywhere;
}

.r01-report-empty {
    padding: 18px;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
    background: #f8fafc;
    color: #64748b;
}

.report-status-generated {
    background: #dfe8ff;
    color: #214ca0;
}

.report-status-sent {
    background: #d9f4e5;
    color: #08743d;
}

.report-status-pending {
    background: #fff1c2;
    color: #7a5200;
}

.report-status-other {
    background: #e9ecef;
    color: #495057;
}

@media (max-width: 900px) {
    .r01-report-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 600px) {
    .r01-report-meta-grid {
        grid-template-columns: 1fr;
    }
}

.summary-grid {
    padding: 16px;
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
}

.summary {
    min-width: 0;
    border: 1px solid #e4e8ed;
    border-radius: 8px;
    padding: 13px;
    text-align: center;
    background: #fafafa;
}

.summary span {
    font-size: 13px;
}

.summary strong {
    display: block;
    font-size: 24px;
    margin-top: 2px;
}

.ok { color: #128044; }
.nc { color: #dc3545; }
.na { color: #6c757d; }
.pe { color: #e09a00; }

.score-box {
    margin: 16px;
    border: 1px solid #cfe2ff;
    border-radius: 10px;
    background: #f4f8ff;
    padding: 18px;
}

.score-layout {
    display: grid;
    grid-template-columns: 1.25fr 1fr 1fr 1fr;
    gap: 15px;
    align-items: stretch;
}

.score-main,
.score-mini {
    background: #fff;
    border: 1px solid #dce6f5;
    border-radius: 9px;
    padding: 14px;
}

.score-main {
    text-align: center;
}

.score-number {
    font-size: 34px;
    font-weight: 800;
    color: #0d6efd;
}

.score-category {
    font-size: 17px;
    font-weight: 700;
    margin-top: 4px;
}

.score-stars {
    font-size: 24px;
    letter-spacing: 2px;
    margin-top: 7px;
}

.score-mini strong {
    display: block;
    font-size: 23px;
    margin-top: 3px;
}

.certificate-workflow {
    margin: 16px;
    padding: 16px;
    border-radius: 10px;
    border: 1px solid #cfe2ff;
    background: #f7fbff;
}

.workflow-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    flex-wrap: wrap;
}

.workflow-info {
    min-width: 0;
}

.workflow-title {
    font-weight: 700;
    font-size: 15px;
}

.workflow-text {
    color: #64748b;
    font-size: 12px;
    margin-top: 3px;
}

.workflow-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.certificate-number {
    font-size: 18px;
    font-weight: 700;
}

.status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 7px 12px;
    border-radius: 18px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
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

.status.generated {
    background: #dfe8ff;
    color: #214ca0;
}

.status.sent {
    background: #d9f4e5;
    color: #08743d;
}

.table-wrap {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.review-table {
    width: 100%;
    max-width: 100%;
    border-collapse: collapse;
    table-layout: auto;
    margin: 0;
}

.review-table th {
    background: #f1f3f5;
    border-bottom: 2px solid #dee2e6;
    padding: 10px 11px;
    font-size: 12px;
    text-align: left;
    vertical-align: middle;
    white-space: nowrap;
    width: auto;
}

.review-table td {
    border-bottom: 1px solid #edf0f2;
    padding: 10px 11px;
    font-size: 13px;
    vertical-align: middle;
    width: auto;
    white-space: normal;
    overflow-wrap: anywhere;
}

.review-table tbody tr:hover {
    background: #fafcff;
}

.review-table th:nth-child(1),
.review-table td:nth-child(1) {
    min-width: 105px;
    white-space: nowrap;
}

.review-table th:nth-child(2),
.review-table td:nth-child(2) {
    min-width: 72px;
    white-space: nowrap;
    text-align: center;
}

.review-table th:nth-child(3),
.review-table td:nth-child(3) {
    min-width: 115px;
}

.review-table th:nth-child(4),
.review-table td:nth-child(4) {
    min-width: 280px;
}

.review-table th:nth-child(5),
.review-table td:nth-child(5) {
    min-width: 130px;
}

.review-table th:nth-child(6),
.review-table td:nth-child(6) {
    min-width: 125px;
}

.review-table th:nth-child(7),
.review-table td:nth-child(7) {
    min-width: 150px;
}

.section-badge {
    display: inline-block;
    min-width: 42px;
    text-align: center;
    padding: 5px 9px;
    border-radius: 14px;
    background: #f1f3f5;
    color: #343a40;
    font-size: 11px;
    font-weight: 800;
    white-space: nowrap;
}

.priority-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    padding: 5px 9px;
    border-radius: 14px;
    background: #eef2ff;
    color: #334155;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.checkpoint-name {
    font-weight: 600;
    line-height: 1.45;
    color: #1f2937;
}

.evidence-links {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.evidence-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 8px;
    border-radius: 7px;
    background: #f1f5f9;
    color: #1d4ed8;
    text-decoration: none;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.evidence-link:hover {
    text-decoration: underline;
}

.result-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    padding: 5px 8px;
    border-radius: 14px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.result-ok {
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

.result-d {
    background: #fff1c2;
    color: #7a5200;
}

.result-ca {
    background: #e7ddff;
    color: #5b2a9d;
}

.result-other {
    background: #e7f1ff;
    color: #0d6efd;
}

.evidence-links {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.evidence-link {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    text-decoration: none;
    border: 1px solid #d7dde5;
    border-radius: 5px;
    padding: 4px 7px;
    font-size: 11px;
    background: #fff;
    white-space: nowrap;
}





.no-evidence {
    color: #6c757d;
    font-size: 11px;
}

.small-muted {
    color: #6c757d;
    font-size: 12px;
}

.technical-justification {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.section-box {
    padding: 14px 18px;
    border-bottom: 1px solid #edf0f2;
}

.section-name {
    font-weight: 700;
    margin-bottom: 4px;
}

.remarks {
    white-space: pre-wrap;
    color: #495057;
    font-size: 13px;
    overflow-wrap: anywhere;
}









.certificate-box {
    margin: 16px;
    padding: 15px;
    border-radius: 10px;
    border: 1px solid #cfe2ff;
    background: #f4f8ff;
}

.read-only-note {
    margin: 0 16px 16px;
    padding: 12px 14px;
    border-radius: 8px;
    background: #f8f9fa;
    color: #6c757d;
    font-size: 12px;
}

@media (max-width: 1100px) {
    .owner-confirmation-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .info-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .summary-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .score-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 700px) {
    .owner-confirmation-grid {
        grid-template-columns: 1fr;
    }

    .admin-review-page {
        padding: 12px;
    }

    .info-grid,
    .summary-grid {
        grid-template-columns: 1fr;
    }

    .review-table {
        width: max-content;
        min-width: 100%;
    }

    .review-table th,
    .review-table td {
        padding: 9px 8px;
    }
}
</style>


<div class="content">

<div class="admin-review-page">


    <?php if ($actionSuccess !== ''): ?>

        <div class="alert alert-success alert-dismissible fade show">

            <i class="bi bi-check-circle me-2"></i>

            <?= h($actionSuccess) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <?php if ($actionError !== ''): ?>

        <div class="alert alert-danger alert-dismissible fade show">

            <i class="bi bi-exclamation-triangle me-2"></i>

            <?= h($actionError) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =====================================================
         HEADER
    ====================================================== -->

    <div class="review-header">

        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">

            <div>

                <h3>
                    <i class="bi bi-clipboard2-check me-2"></i>
                    Admin Checklist Review
                </h3>

                <p>
                    Complete SAF / PAT inspection information,
                    final compliance score and certificate workflow.
                </p>

            </div>


            <div>

                <?php if (
                    $job['job_status'] === 'approved'
                ): ?>

                    <span class="status approved">
                        Inspection Approved
                    </span>

                <?php elseif (
                    $job['job_status'] === 'pending_owner'
                ): ?>

                    <span class="status pending">
                        Pending Owner
                    </span>

                <?php elseif (
                    $job['job_status'] === 'rejected'
                ): ?>

                    <span class="status rejected">
                        Rejected
                    </span>

                <?php else: ?>

                    <span
                        class="status pending"
                    >
                        <?= h(
                            ucfirst(
                                str_replace(
                                    '_',
                                    ' ',
                                    (string)(
                                        $job['job_status']
                                        ??
                                        'Unknown'
                                    )
                                )
                            )
                        ) ?>
                    </span>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =====================================================
         INSPECTION INFORMATION
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-info-circle me-2"></i>
            Inspection Information
        </div>

        <div class="info-grid">

            <div class="info-item">
                <div class="info-label">Asset / Lift</div>
                <div class="info-value">
                    <?= h($displayAsset) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Owner</div>
                <div class="info-value">
                    <?= h(
                        $job['owner_name']
                        ?: '—'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Technician</div>
                <div class="info-value">
                    <?= h(
                        $job['technician_name']
                        ?: '—'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Service</div>
                <div class="info-value">
                    <?= h(
                        $job['service_name']
                        ?:
                        $job['service_code']
                        ?:
                        'PAT'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">PSGA No.</div>
                <div class="info-value">
                    <?= h(
                        $job['psga_no']
                        ?: '—'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">SAF No.</div>
                <div class="info-value">
                    <?= h(
                        $job['saf_no']
                        ?: '—'
                    ) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Inspection Date</div>
                <div class="info-value">
                    <?= h($inspectionDateText) ?>
                </div>
            </div>

            <div class="info-item">
                <div class="info-label">Location</div>
                <div class="info-value">
                    <?= h(
                        $job['location']
                        ?: '—'
                    ) ?>
                </div>
            </div>

        </div>

    </div>


    <!-- =====================================================
         OWNER RESPONSIBILITY CONFIRMATION
         -----------------------------------------------------
         One-time confirmation for ALL NC + CA checkpoints.
         Owner submits one confirmation and one document.
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-person-check me-2"></i>
            Owner Responsibility Confirmation — NC / CA
        </div>

        <div class="owner-confirmation-admin">

            <div class="owner-confirmation-status-row">

                <div>
                    <div class="owner-confirmation-heading">
                        One-Time Owner Confirmation
                    </div>

                    <div class="owner-confirmation-text">
                        This confirmation applies to all NC and CA checkpoints
                        in this inspection. The Owner is confirming responsibility
                        for rectification/correction and submission of the required
                        supporting document.
                    </div>
                </div>

                <?php if ($ownerResponsibilityConfirmed): ?>

                    <span class="status approved">
                        <i class="bi bi-check-circle me-1"></i>
                        Owner Confirmation Submitted
                    </span>

                <?php else: ?>

                    <span class="status pending">
                        <i class="bi bi-hourglass-split me-1"></i>
                        Pending Owner Confirmation
                    </span>

                <?php endif; ?>

            </div>

            <div class="owner-confirmation-grid">

                <div class="owner-confirmation-item">
                    <div class="owner-confirmation-label">
                        Status
                    </div>
                    <div class="owner-confirmation-value">
                        <?php if ($ownerResponsibilityConfirmed): ?>
                            Confirmed
                        <?php else: ?>
                            Pending
                        <?php endif; ?>
                    </div>
                </div>

                <div class="owner-confirmation-item">
                    <div class="owner-confirmation-label">
                        Owner
                    </div>
                    <div class="owner-confirmation-value">
                        <?= h(
                            $ownerResponsibilityOwnerName
                            ?: ($job['owner_name'] ?: '—')
                        ) ?>
                    </div>
                </div>

                <div class="owner-confirmation-item">
                    <div class="owner-confirmation-label">
                        Confirmation Date / Time
                    </div>
                    <div class="owner-confirmation-value">
                        <?php if ($ownerResponsibilityConfirmedAt !== ''): ?>
                            <?= h(
                                formatIST(
                                    $ownerResponsibilityConfirmedAt,
                                    'Asia/Kolkata'
                                )
                            ) ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </div>
                </div>

                <div class="owner-confirmation-item">
                    <div class="owner-confirmation-label">
                        Supporting Document
                    </div>
                    <div class="owner-confirmation-value">

                        <?php if ($ownerResponsibilityDocument !== ''): ?>

                            <a
                                href="<?= h(
                                    evidenceUrl(
                                        $ownerResponsibilityDocument
                                    )
                                ) ?>"
                                target="_blank"
                                rel="noopener"
                                class="evidence-link"
                            >
                                <i class="bi bi-file-earmark-check"></i>
                                View Supporting Document
                            </a>

                        <?php else: ?>

                            <span class="small-muted">
                                Not submitted
                            </span>

                        <?php endif; ?>

                    </div>
                </div>

            </div>

            <?php if (!$ownerResponsibilityConfirmed): ?>

                <div class="owner-confirmation-alert pending-alert">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    Owner has not yet submitted the one-time NC/CA responsibility
                    confirmation and supporting document.
                </div>

            <?php else: ?>

                <div class="owner-confirmation-alert success-alert">
                    <i class="bi bi-check-circle me-2"></i>
                    Owner has completed the one-time responsibility confirmation.
                    Admin can view the submitted supporting document above.
                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         INSPECTION REPORT — R01
         -----------------------------------------------------
         Admin can see the generated R01 PDF for this exact
         inspection job here.
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-file-earmark-pdf me-2"></i>
            Inspection Report — R01
        </div>

        <div class="r01-report-admin">

            <?php if (!$reportTableExists): ?>

                <div class="r01-report-empty">
                    <i class="bi bi-exclamation-circle me-2"></i>
                    The report request module is not configured because
                    the <strong>report_requests</strong> table was not found.
                </div>

            <?php elseif (!is_array($reportRequest)): ?>

                <div class="r01-report-empty">
                    <i class="bi bi-file-earmark-x me-2"></i>
                    No R01 report request has been created for this inspection yet.
                </div>

            <?php else: ?>

                <div class="r01-report-layout">

                    <div class="r01-report-main">

                        <div class="r01-report-title">
                            <i class="bi bi-file-earmark-pdf me-1"></i>
                            Final Inspection Report
                        </div>

                        <div class="r01-report-description">
                            This is the R01 inspection report linked to the current
                            SAF inspection job. Admin can open the generated PDF
                            directly from this section.
                        </div>

                        <?php if ($adminReportNumber !== ''): ?>

                            <div class="r01-report-number">
                                <?= h($adminReportNumber) ?>
                            </div>

                        <?php else: ?>

                            <div class="r01-report-number">
                                R01 Report
                            </div>

                        <?php endif; ?>

                        <div class="r01-report-actions">

                            <?php if ($adminReportPath !== ''): ?>

                                <a
                                    href="<?= h(
                                        evidenceUrl($adminReportPath)
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn btn-primary btn-sm"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View Report PDF
                                </a>

                            <?php else: ?>

                                <span class="status report-status-pending">
                                    <i class="bi bi-hourglass-split me-1"></i>
                                    PDF Not Generated Yet
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div class="r01-report-meta">

                        <div class="r01-report-meta-grid">

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    Report Status
                                </div>

                                <div class="r01-report-meta-value">

                                    <?php if (
                                        $adminReportStatus === 'generated'
                                    ): ?>

                                        <span class="status report-status-generated">
                                            Generated
                                        </span>

                                    <?php elseif (
                                        $adminReportStatus === 'sent_to_owner'
                                        ||
                                        $adminReportStatus === 'sent'
                                    ): ?>

                                        <span class="status report-status-sent">
                                            Sent to Owner
                                        </span>

                                    <?php elseif (
                                        $adminReportStatus === 'requested'
                                        ||
                                        $adminReportStatus === 'pending'
                                    ): ?>

                                        <span class="status report-status-pending">
                                            Requested / Pending
                                        </span>

                                    <?php else: ?>

                                        <span class="status report-status-other">
                                            <?= h(
                                                $adminReportStatus !== ''
                                                    ? ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            $adminReportStatus
                                                        )
                                                    )
                                                    : 'Not Available'
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    Report Request ID
                                </div>

                                <div class="r01-report-meta-value">
                                    #<?= h(
                                        $reportRequest['id'] ?? '—'
                                    ) ?>
                                </div>

                            </div>

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    Requested At
                                </div>

                                <div class="r01-report-meta-value">
                                    <?php if ($adminReportRequestedAt !== ''): ?>
                                        <?= h(
                                            formatDbUtcToIST(
                                                $adminReportRequestedAt
                                            )
                                        ) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>

                            </div>

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    Generated At
                                </div>

                                <div class="r01-report-meta-value">
                                    <?php if ($adminReportGeneratedAt !== ''): ?>
                                        <?= h(
                                            formatDbUtcToIST(
                                                $adminReportGeneratedAt
                                            )
                                        ) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>

                            </div>

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    Sent At
                                </div>

                                <div class="r01-report-meta-value">
                                    <?php if ($adminReportSentAt !== ''): ?>
                                        <?= h(
                                            formatDbUtcToIST(
                                                $adminReportSentAt
                                            )
                                        ) ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </div>

                            </div>

                            <div class="r01-report-meta-item">

                                <div class="r01-report-meta-label">
                                    PDF File
                                </div>

                                <div class="r01-report-meta-value">
                                    <?php if ($adminReportPath !== ''): ?>
                                        Available
                                    <?php else: ?>
                                        Not Generated
                                    <?php endif; ?>
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         INSPECTOR SUMMARY
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-bar-chart me-2"></i>
            Inspector Summary
        </div>

        <div class="summary-grid">

            <div class="summary">
                <span>Total Checkpoints</span>
                <strong><?= $totalCount ?></strong>
            </div>

            <div class="summary ok">
                <span>C</span>
                <strong><?= $okCount ?></strong>
            </div>

            <div class="summary nc">
                <span>NC</span>
                <strong><?= $ncCount ?></strong>
            </div>

            <div class="summary na">
                <span>NA</span>
                <strong><?= $naCount ?></strong>
            </div>

            <div class="summary" style="color:#8a5a00;">
                <span>CA</span>
                <strong><?= $caCount ?></strong>
            </div>

        </div>

    </div>


    <!-- =====================================================
         AUTOMATIC COMPLIANCE / RATING
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-speedometer2 me-2"></i>
            Automatic Compliance & Certificate Rating
        </div>


        <div class="score-box">

            <div class="score-layout">

                <div class="score-main">

                    <div class="small-muted">
                        Automatic Compliance Score
                    </div>

                    <div class="score-number">
                        <?= h(
                            number_format(
                                $compliance,
                                2
                            )
                        ) ?>%
                    </div>

                    <div class="score-category">
                        <?= h($category) ?>
                    </div>

                    <div class="score-stars">
                        <?= h($stars) ?>
                    </div>

                    <div class="small-muted mt-2">
                        Admin Certificate Rating
                    </div>

                </div>


                <div class="score-mini">

                    <div class="small-muted">
                        Total Submitted Checkpoints
                    </div>

                    <strong>
                        <?= (int)$totalCount ?>
                    </strong>

                    <div class="small-muted mt-1">
                        C + NC + CA + NA
                    </div>

                </div>


                <div class="score-mini">

                    <div class="small-muted">
                        Effective Score Basis
                    </div>

                    <strong>
                        <?= (int)$score['C'] ?>
                        /
                        <?= (int)$score['denominator'] ?>
                    </strong>

                    <div class="small-muted mt-1">
                        C + NC only
                    </div>

                </div>


                <div class="score-mini">

                    <div class="small-muted">
                        Certificate Readiness
                    </div>

                    <?php if ($score['pending']): ?>

                        <strong class="text-warning">
                            Pending
                        </strong>

                        <div class="small-muted mt-1">
                            <?= (int)$score['CA_PENDING'] ?>
                            CA checkpoint(s) awaiting Owner approval
                        </div>

                    <?php else: ?>

                        <strong class="text-success">
                            Ready
                        </strong>

                        <div class="small-muted mt-1">
                            No unresolved CA checkpoint pending
                        </div>

                    <?php endif; ?>

                </div>

            </div>            </div>


            <div class="small-muted mt-3">

                <div class="small-muted mt-2">
                <strong>CA Summary:</strong>
                <?= (int)$score['CA_APPROVED'] ?> approved
                &nbsp; | &nbsp;
                <?= (int)$score['CA_REJECTED'] ?> rejected
                &nbsp; | &nbsp;
                <?= (int)$score['CA_PENDING'] ?> awaiting Owner approval
                &nbsp; | &nbsp;
                <strong>Approved CA → C</strong>
                &nbsp; | &nbsp;
                <strong>Rejected CA → NC</strong>
            </div>

        </div>


        <!-- =================================================
             CERTIFICATE WORKFLOW
        ================================================== -->

        <div class="certificate-workflow">

            <div class="workflow-row">


                <div class="workflow-info">

                    <div class="workflow-title">

                        <i class="bi bi-award me-1"></i>

                        Certificate Workflow

                    </div>

                    <div class="workflow-text">

                        Owner requests the certificate after
                        the final R01 report. Admin generates it
                        here using the automatic compliance score.

                    </div>


                    <div class="mt-2">

                        <?php if (
                            $certificateStatus ===
                            'requested'
                        ): ?>

                            <span class="status pending">
                                Certificate Requested by Owner
                            </span>

                        <?php elseif (
                            $certificateStatus ===
                            'generated'
                        ): ?>

                            <span class="status generated">
                                Certificate Generated
                            </span>

                        <?php elseif (
                            $certificateStatus ===
                            'sent'
                        ): ?>

                            <span class="status sent">
                                Certificate Sent to Owner
                            </span>

                        <?php else: ?>

                            <span
                                class="status"
                                style="background:#e9ecef;color:#495057;"
                            >
                                No Certificate Request
                            </span>

                        <?php endif; ?>

                    </div>

                </div>


                <div class="workflow-actions">


                    <?php if (
                        $certificateStatus ===
                        'requested'
                    ): ?>

                        <form
                            method="POST"
                            onsubmit="
                                return confirm(
                                    'Generate the Admin Certificate using the automatic compliance score of <?= h(number_format($compliance, 2)) ?>% and rating <?= h($stars) ?>?'
                                );
                            "
                        >

                            <?= csrfField() ?>

                            <input
                                type="hidden"
                                name="job_id"
                                value="<?= (int)$jobId ?>"
                            >

                            <input
                                type="hidden"
                                name="certificate_action"
                                value="generate"
                            >

                            <button
                                type="submit"
                                class="btn btn-primary btn-sm"
                                <?= $score['pending']
                                    ? 'disabled'
                                    : ''
                                ?>
                            >

                                <i class="bi bi-file-earmark-medical me-1"></i>

                                Generate Certificate

                            </button>

                        </form>


                    <?php elseif (
                        $certificateStatus ===
                        'generated'
                    ): ?>

                        <?php if (
                            $certificateFile !== ''
                        ): ?>

                            <a
                                href="<?= h(
                                    evidenceUrl(
                                        $certificateFile
                                    )
                                ) ?>"
                                target="_blank"
                                class="btn btn-outline-primary btn-sm"
                            >

                                <i class="bi bi-eye me-1"></i>

                                View Certificate

                            </a>

                        <?php endif; ?>


                        <form
                            method="POST"
                            onsubmit="
                                return confirm(
                                    'Send this certificate to the Owner?'
                                );
                            "
                        >

                            <?= csrfField() ?>

                            <input
                                type="hidden"
                                name="job_id"
                                value="<?= (int)$jobId ?>"
                            >

                            <input
                                type="hidden"
                                name="certificate_action"
                                value="send"
                            >

                            <button
                                type="submit"
                                class="btn btn-success btn-sm"
                            >

                                <i class="bi bi-send-check me-1"></i>

                                Send Certificate to Owner

                            </button>

                        </form>


                    <?php elseif (
                        $certificateStatus ===
                        'sent'
                    ): ?>

                        <?php if (
                            $certificateFile !== ''
                        ): ?>

                            <a
                                href="<?= h(
                                    evidenceUrl(
                                        $certificateFile
                                    )
                                ) ?>"
                                target="_blank"
                                class="btn btn-outline-primary btn-sm"
                            >

                                <i class="bi bi-eye me-1"></i>

                                View Certificate

                            </a>

                        <?php endif; ?>


                        <form
                            method="POST"
                            onsubmit="
                                return confirm(
                                    'Send the certificate to the Owner again?'
                                );
                            "
                        >

                            <?= csrfField() ?>

                            <input
                                type="hidden"
                                name="job_id"
                                value="<?= (int)$jobId ?>"
                            >

                            <input
                                type="hidden"
                                name="certificate_action"
                                value="send"
                            >

                            <button
                                type="submit"
                                class="btn btn-success btn-sm"
                            >

                                <i class="bi bi-send-check me-1"></i>

                                Send Again

                            </button>

                        </form>

                    <?php endif; ?>


                </div>

            </div>


            <?php if (
                $certificateStatus ===
                'generated'
                ||
                $certificateStatus ===
                'sent'
            ): ?>

                <div class="certificate-box mt-3 mb-0">

                    <div class="small-muted">
                        Certificate Number
                    </div>

                    <div class="certificate-number">
                        <?= h(
                            $job['certificate_no']
                            ?:
                            'Generated'
                        ) ?>
                    </div>

                    <?php if (
                        !empty(
                            $job[
                                'certificate_generated_at'
                            ]
                        )
                    ): ?>

                        <div class="small-muted mt-1">

                            Generated:

                            <?= h(
                                formatIST(
                                    $job[
                                        'certificate_generated_at'
                                    ],
                                    'UTC'
                                )
                            ) ?>

                        </div>

                    <?php endif; ?>

                    <div class="small-muted mt-2">

                        Admin Certificate Rating:

                        <strong>
                            <?= h($stars) ?>
                        </strong>

                        &nbsp; | &nbsp;

                        Compliance:

                        <strong>
                            <?= h(
                                number_format(
                                    $compliance,
                                    2
                                )
                            ) ?>%
                        </strong>

                    </div>

                </div>

            <?php endif; ?>


        </div>

    </div>


    <!-- =====================================================
         COMPLETE CHECKLIST
         -----------------------------------------------------
         Matches current CHK-01 Rev.1:
         - PAT-001 -> PAT-131 fixed checkpoints
         - D2 floor-wise checkpoints
         - C / NC / NA / CA
         - NC remark + mandatory photo visibility
         - CA technical justification + Owner decision
         - Video/document evidence when present
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-list-check me-2"></i>
            Complete Checklist — CHK-01 Rev.1
        </div>

        <div class="read-only-note">
            <i class="bi bi-shield-check me-2"></i>
            Read-only Admin review. Inspector results are preserved exactly as submitted.
            NC requires Engineering Remark and applicable evidence. CA requires Technical Justification
            and Owner Conditional Acceptance decision. SAT matrix rows and their actual readings
            are included when present in the submitted checklist.
        </div>

        <?php if (empty($results) && empty($floorChecklist)): ?>

            <div class="p-4 text-muted">
                No checklist data found.
            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="review-table">

                    <thead>
                    <tr>
                        <th>Checkpoint</th>
                        <th>Priority</th>
                        <th>Section</th>
                        <th>Checkpoints</th>
                        <th>Actual Reading</th>
                        <th>Result</th>
                        <th>Remarks</th>
                        <th>Technical Justification</th>
                        <th>Owner CA Decision</th>
                        <th>Evidence</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($results as $ref => $item): ?>

                        <?php
                        if (!is_array($item)) {
                            continue;
                        }

                        $refText = (string)$ref;

                        $resultRaw = strtoupper(trim((string)(
                            $item['result'] ?? ''
                        )));

                        $result = resultLabel($resultRaw);

                        $rowMeta =
                            $displayMeta[$refText]
                            ??
                            checkpointMetaForAdmin(
                                $refText,
                                $item,
                                $checklist
                            );

                        $section = $rowMeta['section'];

                        /*
                         * Description column intentionally shows the
                         * actual checkpoint name.
                         */
                        $description = $rowMeta['name'];

                        $priority = $rowMeta['priority'];

                        $remark = (string)($item['remark'] ?? '');

                        $technicalJustification =
                            (string)(
                                $item['technical_justification']
                                ??
                                $item['technicalJustification']
                                ??
                                ''
                            );

                        $caDecision =
                            is_array($item['owner_ca_decision'] ?? null)
                            ? $item['owner_ca_decision']
                            : [];

                        $caDecisionStatus =
                            strtolower(trim((string)(
                                $caDecision['status'] ?? ''
                            )));

                        $caDecisionText =
                            (string)($caDecision['decision_text'] ?? '');

                        $caDecisionReason =
                            (string)($caDecision['reason'] ?? '');

                        /*
                         * Photo and Video are resolved independently.
                         * If BOTH exist, BOTH are displayed.
                         */
                        $photo = evidenceValue(
                            $item,
                            'photo',
                            $databaseEvidence,
                            $refText
                        );

                        $video = evidenceValue(
                            $item,
                            'video',
                            $databaseEvidence,
                            $refText
                        );

                        $document = evidenceValue(
                            $item,
                            'document',
                            $databaseEvidence,
                            $refText
                        );
                        ?>

                        <tr>

                            <td>
                                <strong><?= h($refText) ?></strong>
                            </td>

                            <td>
                                <span class="priority-badge">
                                    <?= h($priority) ?>
                                </span>
                            </td>

                            <td><?= h($section) ?></td>

                            <td>
                                <div class="checkpoint-name">
                                    <?= h($description) ?>
                                </div>
                            </td>

                            <td>
                                <?php
                                $actualReading = adminActualReading($item);
                                ?>
                                <?php if ($actualReading !== ''): ?>
                                    <strong><?= nl2br(h($actualReading)) ?></strong>
                                <?php else: ?>
                                    <span class="small-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <span class="result-badge <?= h(resultClass($resultRaw)) ?>">
                                    <?= h($result) ?>
                                </span>
                            </td>

                            <td>
                                <?php if (trim($remark) !== ''): ?>
                                    <?= nl2br(h($remark)) ?>
                                <?php else: ?>
                                    <span class="small-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if (trim($technicalJustification) !== ''): ?>
                                    <div class="technical-justification">
                                        <?= nl2br(h($technicalJustification)) ?>
                                    </div>
                                <?php elseif ($resultRaw === 'CA'): ?>
                                    <span class="small-muted">Not recorded</span>
                                <?php else: ?>
                                    <span class="small-muted">—</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($resultRaw !== 'CA'): ?>

                                    <span class="small-muted">Not Applicable</span>

                                <?php elseif ($caDecisionStatus === 'approved'): ?>

                                    <span class="status approved">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Owner Approved
                                    </span>

                                    <?php if ($caDecisionText !== ''): ?>
                                        <div class="small-muted mt-1">
                                            <?= h($caDecisionText) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($caDecision['decided_at_ist'])): ?>
                                        <div class="small-muted mt-1">
                                            <?= h(formatIST(
                                                $caDecision['decided_at_ist'],
                                                'Asia/Kolkata'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php elseif ($caDecisionStatus === 'rejected'): ?>

                                    <span class="status rejected">
                                        <i class="bi bi-x-circle me-1"></i>
                                        Owner Rejected
                                    </span>

                                    <?php if ($caDecisionReason !== ''): ?>
                                        <div class="small-muted mt-1">
                                            Reason:
                                            <?= nl2br(h($caDecisionReason)) ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (!empty($caDecision['decided_at_ist'])): ?>
                                        <div class="small-muted mt-1">
                                            <?= h(formatIST(
                                                $caDecision['decided_at_ist'],
                                                'Asia/Kolkata'
                                            )) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="status pending">
                                        <i class="bi bi-hourglass-split me-1"></i>
                                        Awaiting Owner Approval
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="evidence-links">

                                    <?php if ($photo !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($photo)) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-image"></i>
                                            Photo
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($video !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($video)) ?>"
                                            target="_blank"
                                            rel="noopener"
                                            class="evidence-link"
                                        >
                                            <i class="bi bi-camera-video"></i>
                                            Video
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($document !== ''): ?>
                                        <a
                                            href="<?= h(evidenceUrl($document)) ?>"
                                            target="_blank"
                                            rel="noopener"
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
                                        <span class="small-muted">
                                            No evidence
                                        </span>
                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>

                    <?php endforeach; ?>


                    <?php if (
                        isset($checklist['matrices'])
                        && is_array($checklist['matrices'])
                        && !empty($checklist['matrices'])
                    ): ?>

                        <?php foreach ($checklist['matrices'] as $matrixRef => $matrixItem): ?>

                            <?php
                            if (!is_array($matrixItem)) {
                                continue;
                            }

                            $matrixResultRaw = strtoupper(trim((string)(
                                $matrixItem['result'] ?? ''
                            )));

                            if (!in_array(
                                $matrixResultRaw,
                                ['C', 'NC', 'NA', 'CA'],
                                true
                            )) {
                                continue;
                            }

                            $matrixRefText = (string)$matrixRef;
                            $matrixMeta = satMatrixMetaForAdmin(
                                $matrixRefText,
                                $matrixItem
                            );

                            $matrixReading = adminActualReading($matrixItem);

                            $matrixRemark = (string)(
                                $matrixItem['remark']
                                ?? $matrixItem['engineering_remark']
                                ?? ''
                            );

                            $matrixJustification = (string)(
                                $matrixItem['technical_justification']
                                ?? $matrixItem['technicalJustification']
                                ?? ''
                            );

                            $matrixCaDecision = is_array(
                                $matrixItem['owner_ca_decision'] ?? null
                            )
                                ? $matrixItem['owner_ca_decision']
                                : [];

                            $matrixCaStatus = strtolower(trim((string)(
                                $matrixCaDecision['status'] ?? ''
                            )));

                            $matrixCaText = (string)(
                                $matrixCaDecision['decision_text'] ?? ''
                            );

                            $matrixCaReason = (string)(
                                $matrixCaDecision['reason'] ?? ''
                            );

                            $matrixPhoto = evidenceValue(
                                $matrixItem,
                                'photo',
                                $databaseEvidence,
                                $matrixRefText
                            );

                            $matrixVideo = evidenceValue(
                                $matrixItem,
                                'video',
                                $databaseEvidence,
                                $matrixRefText
                            );

                            $matrixDocument = evidenceValue(
                                $matrixItem,
                                'document',
                                $databaseEvidence,
                                $matrixRefText
                            );
                            ?>

                            <tr>

                                <td>
                                    <strong>SAT-<?= h($matrixRefText) ?></strong>
                                </td>

                                <td>
                                    <span class="priority-badge">
                                        <?= h($matrixMeta['priority']) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="section-badge">
                                        <?= h($matrixMeta['section']) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="checkpoint-name">
                                        <?= h($matrixMeta['name']) ?>
                                    </div>
                                    <?php if ($matrixMeta['condition'] !== ''): ?>
                                        <div class="small-muted mt-1">
                                            Load Condition:
                                            <?= h($matrixMeta['condition']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($matrixReading !== ''): ?>
                                        <strong><?= nl2br(h($matrixReading)) ?></strong>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="result-badge <?= h(
                                        resultClass($matrixResultRaw)
                                    ) ?>">
                                        <?= h(resultLabel($matrixResultRaw)) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (trim($matrixRemark) !== ''): ?>
                                        <?= nl2br(h($matrixRemark)) ?>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (trim($matrixJustification) !== ''): ?>
                                        <div class="technical-justification">
                                            <?= nl2br(h($matrixJustification)) ?>
                                        </div>
                                    <?php elseif ($matrixResultRaw === 'CA'): ?>
                                        <span class="small-muted">Not recorded</span>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if ($matrixResultRaw !== 'CA'): ?>

                                        <span class="small-muted">
                                            Not Applicable
                                        </span>

                                    <?php elseif ($matrixCaStatus === 'approved'): ?>

                                        <span class="status approved">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Owner Approved
                                        </span>

                                        <?php if ($matrixCaText !== ''): ?>
                                            <div class="small-muted mt-1">
                                                <?= h($matrixCaText) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($matrixCaDecision['decided_at_ist'])): ?>
                                            <div class="small-muted mt-1">
                                                <?= h(formatIST(
                                                    $matrixCaDecision['decided_at_ist'],
                                                    'Asia/Kolkata'
                                                )) ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php elseif ($matrixCaStatus === 'rejected'): ?>

                                        <span class="status rejected">
                                            <i class="bi bi-x-circle me-1"></i>
                                            Owner Rejected
                                        </span>

                                        <?php if ($matrixCaReason !== ''): ?>
                                            <div class="small-muted mt-1">
                                                Reason:
                                                <?= nl2br(h($matrixCaReason)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($matrixCaDecision['decided_at_ist'])): ?>
                                            <div class="small-muted mt-1">
                                                <?= h(formatIST(
                                                    $matrixCaDecision['decided_at_ist'],
                                                    'Asia/Kolkata'
                                                )) ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="status pending">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            Awaiting Owner Approval
                                        </span>

                                    <?php endif; ?>
                                </td>

                                <td>
                                    <div class="evidence-links">

                                        <?php if ($matrixPhoto !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($matrixPhoto)) ?>"
                                                target="_blank"
                                                rel="noopener"
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
                                                rel="noopener"
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
                                                rel="noopener"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-file-earmark"></i>
                                                Document
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            $matrixPhoto === ''
                                            &&
                                            $matrixVideo === ''
                                            &&
                                            $matrixDocument === ''
                                        ): ?>
                                            <span class="small-muted">
                                                No evidence
                                            </span>
                                        <?php endif; ?>

                                    </div>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>


                    <?php foreach ($floorChecklist as $floor => $floorItems): ?>

                        <?php foreach ($floorItems as $checkpoint => $item): ?>

                            <?php
                            if (!is_array($item)) {
                                continue;
                            }

                            $floorResultRaw =
                                strtoupper(trim((string)(
                                    $item['result'] ?? ''
                                )));

                            $floorResult =
                                resultLabel($floorResultRaw);

                            $floorMetaKey =
                                'FLOOR|' . $floor . '|' . $checkpoint;

                            $floorMeta =
                                $displayMeta[$floorMetaKey]
                                ??
                                checkpointMetaForAdmin(
                                (string)$checkpoint,
                                $item
                            );

                            $floorDescription =
                                $floorMeta['name'];

                            $floorPriority =
                                $floorMeta['priority'];

                            $floorRemark =
                                (string)($item['remark'] ?? '');

                            $floorJustification =
                                (string)(
                                    $item['technical_justification']
                                    ??
                                    $item['ca_remark']
                                    ??
                                    ''
                                );

                            $floorCaDecision =
                                is_array(
                                    $item['owner_ca_decision'] ?? null
                                )
                                ? $item['owner_ca_decision']
                                : [];

                            $floorCaStatus =
                                strtolower(trim((string)(
                                    $floorCaDecision['status'] ?? ''
                                )));

                            $floorPhoto = evidenceValue(
                                $item,
                                'photo',
                                $databaseEvidence,
                                (string)$checkpoint
                            );

                            $floorVideo = evidenceValue(
                                $item,
                                'video',
                                $databaseEvidence,
                                (string)$checkpoint
                            );

                            $floorDocument = evidenceValue(
                                $item,
                                'document',
                                $databaseEvidence,
                                (string)$checkpoint
                            );
                            ?>

                            <tr>

                                <td>
                                    <strong>
                                        D2 / <?= h($floor) ?>
                                    </strong>
                                    <div class="small-muted">
                                        <?= h($checkpoint) ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="priority-badge">
                                        <?= h($floorPriority) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="section-badge">
                                        <?= h(
                                            $floorMeta['section']
                                        ) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="checkpoint-name">
                                        <?= h($floorDescription) ?>
                                    </div>
                                </td>

                                <td>
                                    <?php
                                    $floorReading = adminActualReading($item);
                                    ?>
                                    <?php if ($floorReading !== ''): ?>
                                        <strong><?= nl2br(h($floorReading)) ?></strong>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="result-badge <?= h(
                                        resultClass($floorResultRaw)
                                    ) ?>">
                                        <?= h($floorResult) ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if (trim($floorRemark) !== ''): ?>
                                        <?= nl2br(h($floorRemark)) ?>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?php if (trim($floorJustification) !== ''): ?>
                                        <div class="technical-justification">
                                            <?= nl2br(h($floorJustification)) ?>
                                        </div>
                                    <?php elseif ($floorResultRaw === 'CA'): ?>
                                        <span class="small-muted">Not recorded</span>
                                    <?php else: ?>
                                        <span class="small-muted">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>

                                    <?php if ($floorResultRaw !== 'CA'): ?>

                                        <span class="small-muted">
                                            Not Applicable
                                        </span>

                                    <?php elseif ($floorCaStatus === 'approved'): ?>

                                        <span class="status approved">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Owner Approved
                                        </span>

                                    <?php elseif ($floorCaStatus === 'rejected'): ?>

                                        <span class="status rejected">
                                            <i class="bi bi-x-circle me-1"></i>
                                            Owner Rejected
                                        </span>

                                        <?php if (!empty($floorCaDecision['reason'])): ?>
                                            <div class="small-muted mt-1">
                                                <?= nl2br(h(
                                                    $floorCaDecision['reason']
                                                )) ?>
                                            </div>
                                        <?php endif; ?>

                                    <?php else: ?>

                                        <span class="status pending">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            Awaiting Owner Approval
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="evidence-links">

                                        <?php if ($floorPhoto !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($floorPhoto)) ?>"
                                                target="_blank"
                                                rel="noopener"
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
                                                rel="noopener"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-camera-video"></i>
                                                Video
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($floorDocument !== ''): ?>
                                            <a
                                                href="<?= h(evidenceUrl($floorDocument)) ?>"
                                                target="_blank"
                                                rel="noopener"
                                                class="evidence-link"
                                            >
                                                <i class="bi bi-file-earmark"></i>
                                                Document
                                            </a>
                                        <?php endif; ?>

                                        <?php if (
                                            $floorPhoto === ''
                                            &&
                                            $floorVideo === ''
                                            &&
                                            $floorDocument === ''
                                        ): ?>
                                            <span class="small-muted">
                                                No evidence
                                            </span>
                                        <?php endif; ?>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         SECTION REMARKS
    ====================================================== -->

    <?php if (!empty($sectionRemarks)): ?>

        <div class="review-card">

            <div class="card-title">
                <i class="bi bi-chat-left-text me-2"></i>
                Section Remarks
            </div>


            <?php foreach (
                $sectionRemarks
                as $section =>
                $remark
            ): ?>

                <?php if (
                    trim(
                        (string)$remark
                    )
                    ===
                    ''
                ) {
                    continue;
                } ?>


                <div class="section-box">

                    <div class="section-name">
                        <?= h($section) ?>
                    </div>

                    <div class="remarks">
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

    <div class="review-card">

        <div class="card-title">
            <i class="bi bi-person-check me-2"></i>
            Owner Approval
        </div>

        <div class="info-grid">

            <div class="info-item">

                <div class="info-label">
                    Status
                </div>

                <div class="info-value">

                    <?php if (
                        $dbJobStatus === 'approved'
                        ||
                        strtolower(
                            trim(
                                $ownerApprovalStatus
                            )
                        )
                        ===
                        'approved'
                    ): ?>

                        <span class="status approved">
                            Approved
                        </span>

                    <?php elseif (
                        strtolower(
                            trim(
                                $ownerApprovalStatus
                            )
                        )
                        ===
                        'rejected'
                    ): ?>

                        <span class="status rejected">
                            Rejected / Returned
                        </span>

                    <?php else: ?>

                        <span class="status pending">
                            Pending
                        </span>

                    <?php endif; ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Owner
                </div>

                <div class="info-value">
                    <?= h(
                        $job['owner_name']
                        ?: '—'
                    ) ?>
                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Approval Date / Time
                </div>

                <div class="info-value">

                    <?= $ownerApprovedAt !== ''
                        ? (
                            $ownerApprovedAtIsExplicitIst
                                ? formatIST(
                                    $ownerApprovedAt,
                                    'Asia/Kolkata'
                                )
                                : formatDbUtcToIST(
                                    $ownerApprovedAt
                                )
                        )
                        : '—'
                    ?>

                </div>

            </div>


            <div class="info-item">

                <div class="info-label">
                    Owner Remarks
                </div>

                <div class="info-value">

                    <?php if (
                        trim(
                            $ownerReason
                        )
                        !==
                        ''
                    ): ?>

                        <?= nl2br(
                            h($ownerReason)
                        ) ?>

                    <?php else: ?>

                        —

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- =====================================================
         FINAL STATUS
    ====================================================== -->

    <div class="review-card">

        <div class="card-title">

            <i class="bi bi-flag me-2"></i>

            Final Inspection Status

        </div>


        <div class="p-4">

            <div class="mb-3">

                <strong>
                    Technician Final Status:
                </strong>

                <span class="ms-2">
                    <?= h($finalStatus) ?>
                </span>

            </div>


            <?php if (
                $certificateStatus ===
                'generated'
                ||
                $certificateStatus ===
                'sent'
            ): ?>

                <div class="certificate-box">

                    <div class="small-muted">
                        Admin Certificate
                    </div>

                    <div class="certificate-number">

                        <?= h(
                            $job[
                                'certificate_no'
                            ]
                            ?:
                            'Generated'
                        ) ?>

                    </div>

                    <div class="small-muted mt-1">

                        Compliance:

                        <strong>
                            <?= h(
                                number_format(
                                    $compliance,
                                    2
                                )
                            ) ?>%
                        </strong>

                        &nbsp; | &nbsp;

                        Category:

                        <strong>
                            <?= h($category) ?>
                        </strong>

                        &nbsp; | &nbsp;

                        Rating:

                        <strong>
                            <?= h($stars) ?>
                        </strong>

                    </div>

                </div>

            <?php endif; ?>


            <div class="read-only-note">

                <i class="bi bi-eye me-2"></i>

                Checklist results are read-only here.
                Compliance and Certificate Rating are
                automatically calculated from the submitted
                checklist results.

            </div>

        </div>

    </div>


</div>

</div>


<?php include('../includes/footer.php'); ?>