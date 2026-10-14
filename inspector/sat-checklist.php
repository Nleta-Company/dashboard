<?php
/* =========================================================
   NLETA INSPECTOR — SAT CHECKLIST
   CHK-02 — SAT MASTER CHECKLIST
   Revision: Rev.1

   SOURCE OF TRUTH:
   SAT Checklist_02_Rev.1.docx

   SAT PHILOSOPHY
   --------------
   SAT is a Functional Performance Test.
   Construction / installation / physical verification already
   completed in PAT is CROSS-LOCKED and is NOT re-inspected.

   SAT SCOPE
   ---------
   A  Load Test & Running Parameters
   B  Load Weighing Device
   C  Insulation Resistance
   D  Overspeed Governor Test
   E  Safety Device Performance Test
   F  Cabin Functional Test
   G  Manual Rescue Operation
   H  Battery Rescue Operation
   I  Automatic Rescue Device (ARD)
   J  Rescue System
   K  Fire Operation
   L  Emergency Power Supply

   RESULT CODES
   ------------
   C  = Pass
   NC = Fail
   NA = Not Applicable
   CA = Conditional Acceptance

   NC RULE
   -------
   NC requires:
      • Photograph
      • Engineering Remark

   CA RULE
   -------
   CA means Conditional Acceptance and requires Engineering Remark / Condition.

   D is NOT a valid SAT result code.

   IMPORTANT
   ---------
   • This page is SAT-only.
   • PAT checkpoints are read-only cross-locked.
   • Current Asset/Job remains locked to the Inspector assignment.
   • Individual asset checklist is saved separately.
   • Final complete SAF submission remains on inspections.php.
   • All displayed times are IST.
========================================================= */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

if (session_status() !== PHP_SESSION_ACTIVE) {
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

/* Stored evidence paths are saved relative to the project root. */
function evidenceUrl(string $storedPath): string
{
    $storedPath = str_replace('\\', '/', trim($storedPath));

    if ($storedPath === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $storedPath)) {
        return $storedPath;
    }

    /* saf-checklist.php is inside /inspector/, so uploads are one level up. */
    return '../' . ltrim($storedPath, '/');
}

function satDateTime($value): string
{
    if (empty($value)) {
        return '—';
    }

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d / m / Y h:i A');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function loadJsonArray($value): array
{
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function csrfTokenValue(): string
{
    if (function_exists('generateCSRFToken')) {
        return (string)generateCSRFToken();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['csrf_token'];
}

function validatePostedCsrf(string $token): bool
{
    if (function_exists('validateCSRFToken')) {
        return (bool)validateCSRFToken($token);
    }

    return !empty($_SESSION['csrf_token'])
        && hash_equals((string)$_SESSION['csrf_token'], $token);
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
$assignmentId = (int)($_GET['assignment_id'] ?? $_POST['assignment_id'] ?? 0);
$selectedAssetId = (int)($_GET['asset_id'] ?? $_POST['selected_asset_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   LOAD ASSIGNED SAT JOB
========================================================= */

$job = null;

$sql = "
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.job_type,
        mj.checklist AS saved_checklist,
        mj.created_at AS job_created_at,
        mj.updated_at AS job_updated_at,

        ia.id AS assignment_id,
        ia.inspector_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,
        ia.submitted_at,

        s.request_id,
        s.psga_no,
        s.saf_no,
        s.service_id AS saf_service_id,
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
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die(
        '<div style="font-family:Arial;padding:30px;color:#842029;background:#f8d7da">'
        . '<h3>SAT Database Error</h3>'
        . '<p>Unable to prepare SAT job query: ' . h($conn->error) . '</p>'
        . '</div>'
    );
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die(
        '<div style="font-family:Arial;padding:30px;color:#842029;background:#f8d7da">'
        . '<h3>SAT Database Error</h3>'
        . '<p>Unable to load SAT job: ' . h($stmt->error) . '</p>'
        . '</div>'
    );
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}

$actualAssignmentId = (int)($job['assignment_id'] ?? 0);
$actualAssetId = (int)($job['asset_id'] ?? 0);
$actualSafId = (int)($job['saf_id'] ?? 0);

if ($assignmentId > 0 && $assignmentId !== $actualAssignmentId) {
    die('Invalid Inspector assignment.');
}

if ($selectedAssetId > 0 && $selectedAssetId !== $actualAssetId) {
    die('The selected Asset does not match this SAT inspection job.');
}

$assignmentId = $actualAssignmentId;
$selectedAssetId = $actualAssetId;

$serviceCode = strtoupper(trim((string)($job['service_code'] ?? '')));

/* =========================================================
   SAT-ONLY GUARD
========================================================= */

if ($serviceCode !== 'SAT') {
    die(
        '<div style="font-family:Arial;padding:30px;color:#842029;background:#f8d7da">'
        . '<h3>SAT Checklist</h3>'
        . '<p>This checklist is configured for SAT service only.</p>'
        . '<p>Current service: <strong>' . h($serviceCode ?: 'Unknown') . '</strong></p>'
        . '<a href="inspections.php">Back to Inspections</a>'
        . '</div>'
    );
}

/* =========================================================
   SAF ACCEPTANCE / JOB ACCESS
========================================================= */

if (
    trim((string)($job['saf_status'] ?? '')) !== ''
    && strcasecmp(trim((string)$job['saf_status']), 'Accepted') !== 0
) {
    die('This SAT/SAF has not been accepted by the Owner.');
}

$jobStatus = strtolower(trim((string)($job['job_status'] ?? '')));
$assignmentStatus = strtolower(trim((string)($job['assignment_status'] ?? '')));

if (
    !in_array($jobStatus, ['assigned', 'in_progress'], true)
    && !in_array($assignmentStatus, ['assigned', 'in_progress'], true)
) {
    die(
        'This SAT inspection is not available for checklist work. Current status: '
        . h($job['job_status'] ?? $job['assignment_status'] ?? 'unknown')
    );
}

/* =========================================================
   SAT MASTER CHECKLIST
   ---------------------------------------------------------
   IDs SAT-001 -> SAT-031 are the controlled fixed tests
   from CHK-02 Rev.1.

   Matrix tests are execution rows and do not create new SAT
   checkpoint IDs.
========================================================= */

$sections = [
    'A' => [
        'title' => 'SECTION A — LOAD TEST & RUNNING PARAMETERS',
        'help' => 'All readings shall be recorded at 0%, 25%, 50%, 75% and 100% load conditions. Values shall remain within OEM permissible limits.',
        'type' => 'load_matrix',
        'priority' => 'P1',
        'parameters' => [
            'up_current' => 'UP Current',
            'down_current' => 'DOWN Current',
            'voltage' => 'Voltage',
            'starting_current' => 'Starting Current',
            'running_current' => 'Running Current',
            'leveling_accuracy' => 'Leveling Accuracy',
        ],
    ],
    'B' => [
        'title' => 'SECTION B — LOAD WEIGHING DEVICE (LWD)',
        'help' => '',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-001', 'text' => 'Load Weighing Device activates correctly', 'priority' => 'P1', 'acceptance' => 'Functional operation'],
            ['ref' => 'SAT-002', 'text' => 'Overload indication at rated load', 'priority' => 'P1', 'acceptance' => 'Overload indication'],
        ],
    ],
    'C' => [
        'title' => 'SECTION C — INSULATION RESISTANCE TEST',
        'help' => 'Acceptance: Greater than 3 MΩ.',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-003', 'text' => 'Phase L1 → Earth', 'priority' => 'P1', 'acceptance' => 'Greater than 3 MΩ'],
            ['ref' => 'SAT-004', 'text' => 'Phase L2 → Earth', 'priority' => 'P1', 'acceptance' => 'Greater than 3 MΩ'],
            ['ref' => 'SAT-005', 'text' => 'Phase L3 → Earth', 'priority' => 'P1', 'acceptance' => 'Greater than 3 MΩ'],
            ['ref' => 'SAT-006', 'text' => 'Control Circuit → Earth', 'priority' => 'P1', 'acceptance' => 'Greater than 3 MΩ'],
        ],
    ],
    'D' => [
        'title' => 'SECTION D — OVERSPEED GOVERNOR TEST',
        'help' => '',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-007', 'text' => 'Car OSG Mechanical Operation', 'priority' => 'P1', 'acceptance' => 'Functional operation'],
            ['ref' => 'SAT-008', 'text' => 'Counterweight OSG Operation (Hanging Pit Only)', 'priority' => 'P1', 'acceptance' => 'Functional operation'],
            ['ref' => 'SAT-009', 'text' => 'Mechanical Tripping Test', 'priority' => 'P1', 'acceptance' => 'Successful trip'],
            ['ref' => 'SAT-010', 'text' => 'Electrical Tripping Test', 'priority' => 'P1', 'acceptance' => 'Successful trip'],
        ],
    ],
    'E' => [
        'title' => 'SECTION E — SAFETY DEVICE PERFORMANCE TEST',
        'help' => 'Progressive Risk Verification. Safety Gear and Buffer Compression are verified at five load conditions.',
        'type' => 'safety_matrix',
        'priority' => 'P1',
        'items' => [
            ['ref' => 'SAT-011', 'text' => 'Phase Protection Device', 'priority' => 'P1', 'acceptance' => 'Functional operation'],
            ['ref' => 'SAT-012', 'text' => 'Over Current Protection', 'priority' => 'P1', 'acceptance' => 'Functional operation'],
            ['ref' => 'SAT-013', 'text' => 'Noise Level Evaluation', 'priority' => 'P3', 'acceptance' => 'Within acceptable level'],
            ['ref' => 'SAT-014', 'text' => 'Ride Quality / Vibration', 'priority' => 'P2', 'acceptance' => 'Acceptable ride quality'],
            ['ref' => 'SAT-015', 'text' => 'Seismic Sensor (If Applicable)', 'priority' => 'P2', 'acceptance' => 'Functional where applicable'],
        ],
    ],
    'F' => [
        'title' => 'SECTION F — CABIN FUNCTIONAL TEST',
        'help' => '',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-016', 'text' => 'CCTV Functional', 'priority' => 'P3', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-017', 'text' => 'Fan Switch Operation', 'priority' => 'P3', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-018', 'text' => 'Anti-skid Cabin Floor', 'priority' => 'P3', 'acceptance' => 'Serviceable'],
            ['ref' => 'SAT-019', 'text' => 'Cabin Lighting (Minimum 200 Lux)', 'priority' => 'P2', 'acceptance' => 'Minimum 200 Lux'],
            ['ref' => 'SAT-020', 'text' => 'Braille Buttons Functional', 'priority' => 'P3', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-021', 'text' => 'Voice Announcement System', 'priority' => 'P3', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-022', 'text' => 'COP Cabinet Lock', 'priority' => 'P2', 'acceptance' => 'Functional / secure'],
            ['ref' => 'SAT-023', 'text' => 'Emergency Light Operation', 'priority' => 'P1', 'acceptance' => 'Functional'],
        ],
    ],
    'G' => [
        'title' => 'SECTION G — MANUAL RESCUE OPERATION',
        'help' => 'Hand Winding / Manual Rescue shall be verified at all load conditions.',
        'type' => 'simple_matrix',
        'matrix_label' => 'Working',
        'priority' => 'P1',
    ],
    'H' => [
        'title' => 'SECTION H — BATTERY RESCUE OPERATION',
        'help' => '',
        'type' => 'simple_matrix',
        'matrix_label' => 'Working',
        'priority' => 'P1',
    ],
    'I' => [
        'title' => 'SECTION I — AUTOMATIC RESCUE DEVICE (ARD)',
        'help' => 'ARD acceptance criteria: Rescue Time < 120 seconds; Level Accuracy ±10 mm; Door Opening Successful; Passenger Release Successful.',
        'type' => 'ard_matrix',
        'priority' => 'P1',
    ],
    'J' => [
        'title' => 'SECTION J — RESCUE SYSTEM',
        'help' => '',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-024', 'text' => 'Manual Rescue System', 'priority' => 'P1', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-025', 'text' => 'Electrical Rescue System', 'priority' => 'P1', 'acceptance' => 'Functional'],
        ],
    ],
    'K' => [
        'title' => 'SECTION K — FIRE OPERATION',
        'help' => 'These functional tests are performed in SAT. Statutory compliance remains in SCI.',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-026', 'text' => "Fireman's Operation", 'priority' => 'P1', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-027', 'text' => 'Fire Mode Travel', 'priority' => 'P1', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-028', 'text' => 'Fire Alarm Integration', 'priority' => 'P1', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-029', 'text' => 'Smoke Detector Interface', 'priority' => 'P1', 'acceptance' => 'Functional'],
        ],
    ],
    'L' => [
        'title' => 'SECTION L — EMERGENCY POWER SUPPLY',
        'help' => '',
        'type' => 'fixed',
        'items' => [
            ['ref' => 'SAT-030', 'text' => 'UPS Operation', 'priority' => 'P1', 'acceptance' => 'Functional'],
            ['ref' => 'SAT-031', 'text' => 'DG Changeover Operation', 'priority' => 'P1', 'acceptance' => 'Functional'],
        ],
    ],
];

$loadConditions = ['0%', '25%', '50%', '75%', '100%'];

/* SAT execution counts: fixed IDs + matrix execution rows. */
$totalFixedSatCheckpoints = 0;
$totalSatMatrixRows = 0;
foreach ($sections as $section) {
    $type = $section['type'] ?? '';
    if ($type === 'fixed') {
        $totalFixedSatCheckpoints += count($section['items'] ?? []);
    } elseif ($type === 'load_matrix') {
        $totalSatMatrixRows += count($section['parameters'] ?? []) * count($loadConditions);
    } elseif ($type === 'safety_matrix') {
        $totalSatMatrixRows += count($section['items'] ?? []) * count($loadConditions);
    } elseif (in_array($type, ['simple_matrix', 'ard_matrix'], true)) {
        $totalSatMatrixRows += count($loadConditions);
    }
}
$totalSatExecutionCheckpoints = $totalFixedSatCheckpoints + $totalSatMatrixRows;

/* =========================================================
   LOAD SAVED SAT CHECKLIST
========================================================= */

$saved = loadJsonArray($job['saved_checklist'] ?? '');

$savedResults = [];
if (isset($saved['results']) && is_array($saved['results'])) {
    $savedResults = $saved['results'];
}

$savedMatrices = [];
if (isset($saved['matrices']) && is_array($saved['matrices'])) {
    $savedMatrices = $saved['matrices'];
}

$existingFinal = is_array($saved['acceptance_result'] ?? null)
    ? $saved['acceptance_result']
    : [];

$existingSignatures = is_array($saved['signatures'] ?? null)
    ? $saved['signatures']
    : [];

$lastSavedAt = trim((string)($saved['saved_at_ist'] ?? ''));

$existingFinalStatus = strtoupper(trim((string)($saved['final_status'] ?? '')));
if (!in_array($existingFinalStatus, ['PASS', 'CONDITIONAL PASS', 'FAIL'], true)) {
    $savedOverallSat = strtoupper(trim((string)($existingFinal['overall_sat'] ?? '')));
    $existingFinalStatus = $savedOverallSat === 'SATISFACTORY'
        ? 'PASS'
        : ($savedOverallSat === 'UNSATISFACTORY' ? 'FAIL' : '');
}

/* =========================================================
   LOAD SAT EVIDENCE
========================================================= */

$evidenceByRef = [];

$evidenceTableCheck = $conn->query("SHOW TABLES LIKE 'saf_checklist_evidence'");

if ($evidenceTableCheck && $evidenceTableCheck->num_rows > 0) {

    $evidenceStmt = $conn->prepare("
        SELECT
            ref_code,
            result,
            photo_path,
            video_path,
            document_path
        FROM saf_checklist_evidence
        WHERE job_id = ?
    ");

    if ($evidenceStmt) {

        $evidenceStmt->bind_param('i', $jobId);
        $evidenceStmt->execute();

        $evidenceResult = $evidenceStmt->get_result();

        while ($eRow = $evidenceResult->fetch_assoc()) {
            $evidenceByRef[(string)$eRow['ref_code']] = $eRow;
        }

        $evidenceStmt->close();
    }
}

/* =========================================================
   LOAD PREVIOUS PAT CHECKLIST FOR CROSS-LOCK DISPLAY
========================================================= */

$patChecklist = [];

$patStmt = $conn->prepare("
    SELECT
        mj.id,
        mj.checklist,
        mj.updated_at
    FROM maintenance_jobs mj
    INNER JOIN saf_contracts s
        ON s.id = mj.saf_id
    WHERE
        mj.saf_id = ?
        AND mj.asset_id = ?
        AND mj.job_type = 'saf_inspection'
        AND UPPER(TRIM(COALESCE(s.service_code, ''))) = 'PAT'
        AND LOWER(TRIM(COALESCE(mj.status, ''))) IN ('closed','approved','completed')
    ORDER BY mj.id DESC
    LIMIT 1
");

if ($patStmt) {

    $patStmt->bind_param('ii', $actualSafId, $actualAssetId);
    $patStmt->execute();

    $patRow = $patStmt->get_result()->fetch_assoc();
    $patStmt->close();

    if ($patRow) {
        $patChecklist = loadJsonArray($patRow['checklist'] ?? '');
    }
}

$crossLockItems = [
    'Car Top Controls',
    'Landing Door Lock',
    'Landing Push Buttons',
    'Hall Display',
    'Limit Switches',
    'Inspection Switch',
    'Alarm',
    'Intercom',
    'Auto Attendant',
    'Running Clearance',
];

/* =========================================================
   EXISTING VALUE HELPERS
========================================================= */

function existingResult(array $results, string $ref): string
{
    return strtoupper(trim((string)($results[$ref]['result'] ?? '')));
}

function existingField(array $results, string $ref, string $field): string
{
    return trim((string)($results[$ref][$field] ?? ''));
}

function existingMatrix(array $matrices, string $key): array
{
    return isset($matrices[$key]) && is_array($matrices[$key])
        ? $matrices[$key]
        : [];
}

function savedEvidenceForRef(array $savedRows, string $ref, array $dbEvidence = []): array
{
    $row = isset($savedRows[$ref]) && is_array($savedRows[$ref]) ? $savedRows[$ref] : [];
    $nested = isset($row['evidence']) && is_array($row['evidence']) ? $row['evidence'] : [];

    /*
     * PAT-style evidence resolver:
     * 1) use DB path when it is actually non-empty
     * 2) otherwise use the flat JSON path
     * 3) otherwise use the nested JSON evidence snapshot
     *
     * This is important because a DB row may exist with an empty
     * column while the same evidence is still present in JSON.
     */
    $photo = trim((string)($dbEvidence['photo_path'] ?? ''));
    if ($photo === '') {
        $photo = trim((string)($row['photo'] ?? ''));
    }
    if ($photo === '') {
        $photo = trim((string)($nested['photo'] ?? ''));
    }

    $video = trim((string)($dbEvidence['video_path'] ?? ''));
    if ($video === '') {
        $video = trim((string)($row['video'] ?? ''));
    }
    if ($video === '') {
        $video = trim((string)($nested['video'] ?? ''));
    }

    $document = trim((string)($dbEvidence['document_path'] ?? ''));
    if ($document === '') {
        $document = trim((string)($row['document'] ?? ''));
    }
    if ($document === '') {
        $document = trim((string)($nested['document'] ?? ''));
    }

    return [
        'photo' => $photo,
        'video' => $video,
        'document' => $document,
    ];
}
function hasSavedEvidence(array $ev): bool
{
    return ($ev['photo'] ?? '') !== '' || ($ev['video'] ?? '') !== '' || ($ev['document'] ?? '') !== '';
}

function renderExistingEvidence(array $ev): string
{
    $html = '';

    if (($ev['photo'] ?? '') !== '') {
        $url = h(evidenceUrl((string)$ev['photo']));
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="existing-evidence-link">'
            . '<i class="bi bi-image"></i> View Existing Photo</a>';
    }

    if (($ev['video'] ?? '') !== '') {
        $url = h(evidenceUrl((string)$ev['video']));
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="existing-evidence-link">'
            . '<i class="bi bi-camera-video"></i> View Existing Video</a>';
    }

    if (($ev['document'] ?? '') !== '') {
        $url = h(evidenceUrl((string)$ev['document']));
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener" class="existing-evidence-link">'
            . '<i class="bi bi-file-earmark-text"></i> View Existing Document</a>';
    }

    return $html;
}

$csrfToken = csrfTokenValue();

$pageMessage = trim((string)($_SESSION['success'] ?? ''));
$pageError = trim((string)($_SESSION['error'] ?? ''));

unset($_SESSION['success'], $_SESSION['error']);

$jobDisplayStatus = ucwords(str_replace('_', ' ', $jobStatus));
?>

<?php include('../includes/header.php'); ?>
<?php include('../includes/sidebar-inspector.php'); ?>

<style>
/* =========================================================
   NLETA SAT CHECKLIST — PROFESSIONAL UI
   Visual-only enhancement.
   PHP / HTML structure / field names / JavaScript behaviour
   remain unchanged.
========================================================= */

:root{
    --sat-primary:#175cd3;
    --sat-primary-dark:#1247a6;
    --sat-primary-soft:#eef5ff;
    --sat-success:#087443;
    --sat-success-soft:#ecfdf3;
    --sat-danger:#b42318;
    --sat-danger-soft:#fff1f0;
    --sat-warning:#b54708;
    --sat-warning-soft:#fffaeb;
    --sat-text:#172b4d;
    --sat-muted:#667085;
    --sat-border:#dfe5ec;
    --sat-border-dark:#cbd5e1;
    --sat-bg:#f5f7fa;
    --sat-white:#ffffff;
    --sat-shadow:0 8px 24px rgba(15,23,42,.06);
    --sat-shadow-lg:0 14px 38px rgba(15,23,42,.08);
    --sat-radius:14px;
}

/* ---------- PAGE ---------- */
.sat-page{
    padding:26px 24px 40px;
    background:
        radial-gradient(circle at top left,rgba(23,92,211,.045),transparent 28%),
        var(--sat-bg);
    min-height:calc(100vh - 70px);
    color:var(--sat-text);
}

.sat-wrap{
    max-width:1450px;
    margin:0 auto;
}

/* ---------- HEADER ---------- */
.sat-head{
    position:relative;
    overflow:hidden;
    background:var(--sat-white);
    border:1px solid var(--sat-border);
    border-radius:18px;
    box-shadow:var(--sat-shadow);
    margin-bottom:20px;
    padding:25px;
}

.sat-head:before{
    content:"";
    position:absolute;
    inset:0 auto 0 0;
    width:5px;
    background:linear-gradient(180deg,var(--sat-primary),#5b8def);
}

.sat-head h3{
    margin:0;
    color:#102a43;
    font-size:24px;
    line-height:1.25;
    font-weight:800;
    letter-spacing:-.35px;
}

.sat-head p{
    margin:8px 0 0;
    color:var(--sat-muted);
    font-size:13px;
    line-height:1.6;
}

.sat-head .text-end{
    padding:10px 14px;
    border:1px solid var(--sat-border);
    border-radius:10px;
    background:#f8fafc;
}

.sat-head .text-end small{
    display:block;
    margin-bottom:3px;
    font-size:10px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.6px;
    color:var(--sat-muted)!important;
}

/* ---------- PROCESS STEPS ---------- */
.sat-steps{
    display:flex;
    gap:9px;
    align-items:center;
    flex-wrap:wrap;
    margin-top:22px;
    padding-top:18px;
    border-top:1px solid #edf0f4;
}

.sat-step{
    display:flex;
    align-items:center;
    gap:8px;
    color:#98a2b3;
    font-size:12px;
    font-weight:600;
}

.sat-step.active{
    color:var(--sat-primary);
    font-weight:800;
}

.sat-step.done{
    color:var(--sat-success);
    font-weight:800;
}

.sat-step > span:not(.sat-num){
    color:#c4cbd4;
}

.sat-num{
    width:31px;
    height:31px;
    flex:0 0 31px;
    border-radius:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    background:#eef1f5;
    border:1px solid #e1e6ed;
    font-size:11px;
    font-weight:800;
}

.sat-step.active .sat-num{
    background:var(--sat-primary);
    border-color:var(--sat-primary);
    color:#fff;
    box-shadow:0 4px 10px rgba(23,92,211,.25);
}

.sat-step.done .sat-num{
    background:var(--sat-success);
    border-color:var(--sat-success);
    color:#fff;
    box-shadow:0 4px 10px rgba(8,116,67,.20);
}

/* ---------- JOB INFORMATION ---------- */
.job-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;
    margin-top:20px;
}

.job-box{
    min-height:68px;
    background:#f8fafc;
    border:1px solid #e5eaf0;
    border-radius:11px;
    padding:12px 14px;
    transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease;
}

.job-box:hover{
    border-color:#cbd8ea;
    box-shadow:0 4px 12px rgba(15,23,42,.04);
    transform:translateY(-1px);
}

.job-label{
    font-size:9px;
    line-height:1.2;
    text-transform:uppercase;
    letter-spacing:.65px;
    color:#667085;
    font-weight:800;
}

.job-value{
    margin-top:6px;
    color:#172b4d;
    font-size:13px;
    line-height:1.4;
    font-weight:700;
    word-break:break-word;
}

/* ---------- ALERTS ---------- */
.sat-page > .sat-wrap > .alert{
    border-radius:11px;
    border-width:1px;
    padding:13px 15px;
    font-size:13px;
    box-shadow:0 3px 10px rgba(15,23,42,.03);
}

/* ---------- RULE / LEGEND ---------- */
.rule-box{
    position:relative;
    background:#fffdf5;
    border:1px solid #f2df9b;
    border-left:4px solid #d99a00;
    padding:15px 17px;
    border-radius:12px;
    margin-bottom:20px;
    font-size:12px;
    line-height:1.65;
    color:#475467;
    box-shadow:0 4px 14px rgba(15,23,42,.03);
}

.rule-box > strong:first-child{
    color:#344054;
    font-size:13px;
}

.legend{
    display:flex;
    gap:7px;
    flex-wrap:wrap;
    margin:10px 0 9px;
}

.legend span{
    display:inline-flex;
    align-items:center;
    min-height:28px;
    border:1px solid transparent;
    border-radius:7px;
    padding:5px 10px;
    font-size:10px;
    font-weight:800;
    letter-spacing:.15px;
}

.legend-c{
    background:#ecfdf3;
    border-color:#abefc6!important;
    color:#067647;
}

.legend-nc{
    background:#fff1f0;
    border-color:#fecdca!important;
    color:#b42318;
}

.legend-na{
    background:#f2f4f7;
    border-color:#d0d5dd!important;
    color:#475467;
}

.legend-d{
    background:#fffaeb;
    border-color:#fedf89!important;
    color:#b54708;
}

/* ---------- SECTION CARDS ---------- */
.section-tools{
    display:flex;
    align-items:center;
    gap:6px;
    flex-wrap:wrap;
    margin-left:auto;
}

.select-all-label{
    color:#667085;
    font-size:9px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.45px;
    white-space:nowrap;
}

.select-all-result{
    min-width:34px;
    min-height:28px;
    padding:4px 8px;
    border:1px solid #cfd6df;
    border-radius:7px;
    background:#fff;
    color:#344054;
    font-size:9px;
    font-weight:900;
    line-height:1;
    cursor:pointer;
    transition:all .16s ease;
}

.select-all-result:hover{
    transform:translateY(-1px);
    box-shadow:0 3px 8px rgba(15,23,42,.08);
}

.select-all-C:hover{
    background:#ecfdf3;
    border-color:#abefc6;
    color:#067647;
}

.select-all-NC:hover{
    background:#fff1f0;
    border-color:#fecdca;
    color:#b42318;
}

.select-all-NA:hover{
    background:#f2f4f7;
    border-color:#d0d5dd;
    color:#475467;
}

.select-all-CA:hover{
    background:#fffaeb;
    border-color:#fedf89;
    color:#b54708;
}

.select-all-result.active{
    box-shadow:0 0 0 2px rgba(23,92,211,.10);
}

.sat-card{
    background:var(--sat-white);
    border:1px solid var(--sat-border);
    border-radius:var(--sat-radius);
    box-shadow:var(--sat-shadow);
    margin-bottom:20px;
    overflow:hidden;
}

.card-head{
    min-height:66px;
    padding:15px 19px;
    border-bottom:1px solid #e8edf2;
    background:linear-gradient(180deg,#fbfcfe 0%,#f7f9fc 100%);
    display:flex;
    justify-content:space-between;
    gap:14px;
    align-items:center;
    flex-wrap:wrap;
}

.card-title{
    color:#102a43;
    font-size:16px;
    line-height:1.35;
    font-weight:800;
    letter-spacing:-.15px;
}

.card-help{
    max-width:950px;
    margin-top:4px;
    color:#667085;
    font-size:11px;
    line-height:1.55;
}

.card-bodyx{
    padding:18px;
}

.section-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:38px;
    padding:5px 10px;
    border-radius:999px;
    background:var(--sat-primary-soft);
    border:1px solid #cfe0ff;
    color:var(--sat-primary);
    font-size:10px;
    font-weight:800;
    letter-spacing:.25px;
}

/* ---------- TABLES ---------- */
.matrix-wrap{
    overflow:auto;
    border:1px solid #e3e8ef;
    border-radius:10px;
    background:#fff;
}

.check-table,
.matrix-table,
.crosslock-table{
    width:100%;
    border-collapse:separate;
    border-spacing:0;
}

.check-table th,
.check-table td,
.matrix-table th,
.matrix-table td{
    border:0;
    border-bottom:1px solid #e5e9ef;
    border-right:1px solid #e5e9ef;
    padding:11px 12px;
    vertical-align:top;
}

.check-table th:last-child,
.check-table td:last-child,
.matrix-table th:last-child,
.matrix-table td:last-child{
    border-right:0;
}

.check-table tbody tr:last-child td,
.matrix-table tbody tr:last-child td{
    border-bottom:0;
}

.check-table th,
.matrix-table th{
    position:sticky;
    top:0;
    z-index:2;
    background:#f8fafc;
    color:#344054;
    font-size:9px;
    line-height:1.3;
    text-transform:uppercase;
    letter-spacing:.55px;
    font-weight:800;
    white-space:nowrap;
}

.check-table tbody tr,
.matrix-table tbody tr{
    transition:background .15s ease;
}

.check-table tbody tr:hover,
.matrix-table tbody tr:hover{
    background:#fbfdff;
}

.check-table td,
.matrix-table td{
    color:#344054;
    font-size:12px;
    line-height:1.5;
}

.check-table td:nth-child(2){
    color:#172b4d;
    font-weight:600;
}

.ref{
    color:var(--sat-primary);
    font-size:12px;
    font-weight:900;
    white-space:nowrap;
}

.priority{
    text-align:center;
    color:#344054;
    font-weight:800;
    white-space:nowrap;
}

/* ---------- RESULT BUTTONS ---------- */
.result-group{
    display:flex;
    gap:6px;
    flex-wrap:wrap;
    align-items:center;
}

.result-pill{
    position:relative;
}

.result-pill input{
    position:absolute;
    width:1px;
    height:1px;
    opacity:0;
    pointer-events:none;
}

.result-pill label{
    min-width:39px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:34px;
    padding:6px 10px;
    border:1px solid #cfd6df;
    border-radius:8px;
    background:#fff;
    color:#344054;
    cursor:pointer;
    font-size:10px;
    font-weight:800;
    letter-spacing:.2px;
    user-select:none;
    transition:all .16s ease;
}

.result-pill label:hover{
    border-color:#98a2b3;
    background:#f8fafc;
    transform:translateY(-1px);
}

.result-pill input:focus-visible + label{
    outline:3px solid rgba(23,92,211,.16);
    outline-offset:2px;
}

.result-pill input:checked + label{
    background:var(--sat-primary);
    color:#fff;
    border-color:var(--sat-primary);
    box-shadow:0 3px 8px rgba(23,92,211,.18);
}

/* Result-specific checked states */
.checkpoint-row.table-danger .result-pill input:checked + label,
.sat-matrix-cell.table-danger .result-pill input:checked + label,
.sat-matrix-row.table-danger .result-pill input:checked + label{
    background:var(--sat-danger);
    border-color:var(--sat-danger);
}

.checkpoint-row.table-warning .result-pill input:checked + label,
.sat-matrix-cell.table-warning .result-pill input:checked + label,
.sat-matrix-row.table-warning .result-pill input:checked + label{
    background:var(--sat-warning);
    border-color:var(--sat-warning);
}

/* ---------- CONDITIONAL FIELDS ---------- */
.conditional-fields,
.matrix-conditional{
    display:none;
    margin-top:10px;
    padding:10px;
    border:1px solid #e4e9ef;
    border-radius:9px;
    background:#f8fafc;
}

.conditional-fields.show,
.matrix-conditional.show{
    display:block;
    animation:satReveal .18s ease-out;
}

@keyframes satReveal{
    from{opacity:0;transform:translateY(-3px)}
    to{opacity:1;transform:translateY(0)}
}

.conditional-label{
    display:block;
    margin-bottom:5px;
    color:#475467;
    font-size:9px;
    line-height:1.3;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}

.required-star{
    color:#d92d20;
}

.form-control-sm,
.form-select-sm{
    min-height:34px;
    border-color:#d0d5dd;
    border-radius:7px;
    color:#172b4d;
    background:#fff;
    font-size:11px;
    box-shadow:none;
    transition:border-color .15s ease,box-shadow .15s ease;
}

.form-control-sm:focus,
.form-select-sm:focus{
    border-color:#84adf4;
    box-shadow:0 0 0 3px rgba(23,92,211,.10);
}

textarea.form-control-sm{
    min-height:64px;
    resize:vertical;
    line-height:1.45;
}

.is-invalid{
    border-color:#f04438!important;
    box-shadow:0 0 0 3px rgba(240,68,56,.08)!important;
}

/* ---------- EVIDENCE ---------- */
.evidence-box{
    display:none;
    margin-top:0;
    padding:11px;
    background:#f8fafc;
    border:1px solid #e3e8ef;
    border-radius:9px;
}

.evidence-box.show{
    display:block;
    animation:satReveal .18s ease-out;
}

.evidence-box label{
    display:block;
    margin-bottom:5px;
    color:#475467;
    font-size:9px;
    line-height:1.3;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.35px;
}

.evidence-box input[type="file"]{
    width:100%;
    padding:4px;
    border:1px solid #d0d5dd;
    border-radius:7px;
    background:#fff;
    font-size:10px;
}

.evidence-box input[type="file"]::file-selector-button{
    margin:-4px 9px -4px -4px;
    padding:7px 10px;
    border:0;
    border-right:1px solid #d0d5dd;
    background:#f2f4f7;
    color:#344054;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
}

.existing-evidence-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-top: 6px;
    padding: 5px 8px;
    border: 1px solid #b7dfc8;
    border-radius: 6px;
    background: #f0fff5;
    color: #087443;
    text-decoration: none;
    font-size: 12px;
    font-weight: 600;
}

.existing-evidence-link:hover {
    background: #e4f9ec;
    color: #065f36;
}

.evidence-box .small.text-success,
.matrix-conditional .small.text-success{
    display:block;
    margin-top:6px!important;
    padding:6px 8px;
    border-radius:6px;
    background:#ecfdf3;
    color:#067647!important;
    font-size:9px!important;
    font-weight:700;
}

/* ---------- MATRIX CELLS ---------- */
.matrix-table{
    min-width:900px;
}

.matrix-table td{
    min-width:145px;
}

.matrix-table td:first-child{
    min-width:170px;
    background:#fbfcfe;
    color:#172b4d;
    font-weight:700;
}

.matrix-table .result-group{
    gap:4px;
}

.matrix-table .result-pill label{
    min-width:34px;
    min-height:30px;
    padding:5px 8px;
}

.sat-matrix-cell.table-danger,
.sat-matrix-row.table-danger td{
    background:#fff8f7;
}

.sat-matrix-cell.table-warning,
.sat-matrix-row.table-warning td{
    background:#fffdf7;
}

/* ---------- ARD CRITERIA ---------- */
.alert.alert-secondary{
    border:1px solid #dbe3ec;
    border-radius:10px;
    background:#f8fafc;
    color:#475467;
    font-size:11px;
    line-height:1.6;
}

/* ---------- ACCEPTANCE RESULT ---------- */
.accept-grid{
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:13px;
}

.accept-box{
    position:relative;
    border:1px solid #e1e6ed;
    border-radius:11px;
    padding:15px;
    background:#fbfcfe;
    transition:border-color .2s ease,box-shadow .2s ease;
}

.accept-box:hover{
    border-color:#cbd8ea;
    box-shadow:0 5px 14px rgba(15,23,42,.04);
}

.accept-label{
    margin-bottom:9px;
    color:#344054;
    font-size:11px;
    font-weight:800;
}

.overall-box{
    margin-top:15px;
    border:1px solid #b8d1fb;
    background:linear-gradient(135deg,#f4f8ff,#eef5ff);
    border-radius:11px;
    padding:16px;
}

.overall-title{
    color:#17395c;
    font-size:13px;
    font-weight:900;
}

/* ---------- SIGNATURES ---------- */
.final-grid{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:13px;
}

.signature-box{
    padding:14px;
    border:1px solid #e1e6ed;
    border-radius:10px;
    background:#fbfcfe;
}

.signature-box label{
    color:#475467;
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.3px;
}

/* ---------- CROSS LOCK ---------- */
.crosslock{
    border:1px solid #b7dfc8;
    background:#f7fffa;
    border-radius:14px;
    overflow:hidden;
}

.crosslock-head{
    padding:14px 17px;
    border-bottom:1px solid #cfe9d9;
    background:linear-gradient(180deg,#f3fff7,#edfaf2);
    color:#0f5132;
    font-size:13px;
    font-weight:800;
}

.crosslock-table th,
.crosslock-table td{
    padding:10px 11px;
    border-right:1px solid #d9eadf;
    border-bottom:1px solid #d9eadf;
    font-size:11px;
    vertical-align:middle;
}

.crosslock-table th:last-child,
.crosslock-table td:last-child{
    border-right:0;
}

.crosslock-table tbody tr:last-child td{
    border-bottom:0;
}

.crosslock-table th{
    background:#eefaf2;
    color:#285c40;
    font-size:9px;
    text-transform:uppercase;
    letter-spacing:.45px;
}

.crosslock-table td{
    color:#344054;
}

.readonly-result{
    display:inline-flex;
    align-items:center;
    padding:4px 9px;
    border-radius:999px;
    background:#dff5e8;
    border:1px solid #b7dfc8;
    color:#0f5132;
    font-size:9px;
    font-weight:800;
}

/* ---------- ACTION BAR ---------- */
/* SAT PROGRESS + FINAL INSPECTION STATUS */
.nleta-card{background:#fff;border:1px solid #e1e5e9;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.06);margin-bottom:18px;overflow:hidden}
.nleta-card-header{padding:20px 22px;border-bottom:1px solid #e5e7eb}
.summary-grid{display:grid;gap:10px;padding:18px 18px 0}
.summary-grid-totals{grid-template-columns:repeat(3,minmax(0,1fr))}
.summary-grid-results{grid-template-columns:repeat(4,minmax(0,1fr));padding-top:0;padding-bottom:18px}
.summary-box{border:1px solid #e1e5e9;border-radius:10px;padding:14px;background:#fff;min-width:0}
.summary-label{color:#6c757d;font-size:12px;font-weight:700}
.summary-value{margin-top:4px;font-size:24px;font-weight:900}
.summary-total{color:#1769ff}.summary-c{color:#168a3a}.summary-nc{color:#dc3545}.summary-na{color:#6c757d}.summary-ca{color:#b57a00}
.final-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px}
.final-option{border:1px solid #ced4da;border-radius:10px;padding:14px;cursor:pointer;font-weight:800;background:#fff}.final-option input{margin-right:7px}
.final-fields{padding:0 18px 18px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.final-field label{display:block;font-size:12px;font-weight:800;margin-bottom:5px}.final-field input{width:100%;border:1px solid #ced4da;border-radius:7px;padding:9px;font-size:13px}
@media(max-width:1000px){.summary-grid-totals{grid-template-columns:repeat(3,minmax(0,1fr))}.summary-grid-results{grid-template-columns:repeat(4,minmax(0,1fr))}.final-fields{grid-template-columns:1fr}}
@media(max-width:650px){.summary-grid-totals{grid-template-columns:1fr}.summary-grid-results{grid-template-columns:repeat(2,minmax(0,1fr))}.final-status-grid{grid-template-columns:1fr}}

.action-bar{
    position:sticky;
    bottom:12px;
    z-index:20;
    background:rgba(255,255,255,.96);
    backdrop-filter:blur(12px);
    border:1px solid #dce3eb;
    border-radius:13px;
    padding:12px 15px;
    box-shadow:0 10px 30px rgba(15,23,42,.12);
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:14px;
    flex-wrap:wrap;
}

.action-bar strong{
    color:#172b4d;
    font-size:12px;
}

.save-note{
    margin-top:3px;
    color:#667085;
    font-size:10px;
    line-height:1.45;
}

.action-bar .btn{
    min-height:36px;
    border-radius:8px;
    padding:7px 13px;
    font-size:11px;
    font-weight:700;
    transition:all .16s ease;
}

.action-bar .btn:hover{
    transform:translateY(-1px);
}

.action-bar .btn-primary{
    background:var(--sat-primary);
    border-color:var(--sat-primary);
    box-shadow:0 4px 10px rgba(23,92,211,.18);
}

.action-bar .btn-primary:hover{
    background:var(--sat-primary-dark);
    border-color:var(--sat-primary-dark);
}

.action-bar .btn-outline-secondary{
    color:#475467;
    border-color:#cfd6df;
    background:#fff;
}

.action-bar .btn-outline-warning{
    color:#b54708;
    border-color:#f0c36b;
    background:#fffaf0;
}

/* ---------- TABLE STATUS HIGHLIGHTS ---------- */
.checkpoint-row.table-danger td{
    background:#fff8f7;
}

.checkpoint-row.table-warning td{
    background:#fffdf7;
}

.checkpoint-row.table-danger td:first-child{
    box-shadow:inset 3px 0 0 #d92d20;
}

.checkpoint-row.table-warning td:first-child{
    box-shadow:inset 3px 0 0 #f79009;
}

/* ---------- SCROLLBAR ---------- */
.matrix-wrap::-webkit-scrollbar{
    height:9px;
    width:9px;
}

.matrix-wrap::-webkit-scrollbar-track{
    background:#f1f3f6;
    border-radius:10px;
}

.matrix-wrap::-webkit-scrollbar-thumb{
    background:#c5ccd5;
    border-radius:10px;
}

.matrix-wrap::-webkit-scrollbar-thumb:hover{
    background:#aeb7c2;
}

/* ---------- RESPONSIVE ---------- */
@media(max-width:1200px){
    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .check-table{
        min-width:900px;
    }
}

@media(max-width:900px){
    .section-tools{
        width:100%;
        margin-left:0;
        padding-top:4px;
    }

    .sat-page{
        padding:18px 14px 30px;
    }

    .sat-head{
        padding:19px;
    }

    .sat-head h3{
        font-size:20px;
    }

    .card-bodyx{
        padding:13px;
    }

    .accept-grid,
    .final-grid{
        grid-template-columns:1fr;
    }

    .action-bar{
        position:static;
    }
}

@media(max-width:650px){
    .section-tools{
        gap:5px;
    }

    .select-all-result{
        flex:1;
        min-width:0;
    }

    .sat-page{
        padding:10px 8px 22px;
    }

    .sat-head{
        border-radius:13px;
        padding:16px;
    }

    .sat-head .text-end{
        width:100%;
        text-align:left!important;
    }

    .job-grid{
        grid-template-columns:1fr;
        gap:8px;
    }

    .job-box{
        min-height:0;
    }

    .sat-steps{
        gap:6px;
    }

    .sat-step{
        font-size:10px;
    }

    .sat-num{
        width:27px;
        height:27px;
        flex-basis:27px;
    }

    .card-head{
        padding:13px 14px;
    }

    .card-title{
        font-size:14px;
    }

    .rule-box{
        padding:12px;
    }

    .action-bar{
        padding:11px;
    }

    .action-bar > div{
        width:100%;
    }

    .action-bar .d-flex{
        width:100%;
    }

    .action-bar .btn{
        flex:1;
    }
}


/* =========================================================
   PROTECTION DEVICES — MOBILE/TABLET NO-HORIZONTAL-SCROLL FIX
   Desktop table remains unchanged. On smaller screens each
   protection-device row becomes a responsive card.
========================================================= */
.protection-devices-table-wrap{
    width:100%;
    overflow:visible;
}

.protection-devices-wrap .check-table{
    width:100%;
    min-width:0;
}

@media(max-width:900px){
    .protection-devices-wrap .check-table{
        table-layout:fixed;
    }

    .protection-devices-wrap .check-table th:nth-child(1),
    .protection-devices-wrap .check-table th:nth-child(3),
    .protection-devices-wrap .check-table th:nth-child(4),
    .protection-devices-wrap .check-table th:nth-child(5){
        width:auto!important;
    }

    .protection-devices-wrap .check-table th:nth-child(2){
        width:auto!important;
    }
}

@media(max-width:650px){
    .protection-devices-wrap{
        margin-top:16px!important;
    }

    .protection-devices-wrap h6{
        margin-bottom:10px!important;
        font-size:13px;
    }

    .protection-devices-table-wrap{
        overflow:visible;
    }

    .protection-devices-wrap .check-table,
    .protection-devices-wrap .check-table tbody,
    .protection-devices-wrap .check-table tr,
    .protection-devices-wrap .check-table td{
        display:block;
        width:100%;
    }

    .protection-devices-wrap .check-table thead{
        display:none;
    }

    .protection-devices-wrap .check-table tbody tr{
        display:grid;
        grid-template-columns:minmax(0,1fr) minmax(0,1fr);
        gap:0;
        margin-bottom:10px;
        border:1px solid #e1e6ed;
        border-radius:11px;
        background:#fff;
        overflow:hidden;
        box-shadow:0 3px 10px rgba(15,23,42,.04);
    }

    .protection-devices-wrap .check-table tbody tr:last-child{
        margin-bottom:0;
    }

    .protection-devices-wrap .check-table td{
        min-width:0!important;
        width:auto!important;
        border:0!important;
        border-bottom:1px solid #edf0f4!important;
        padding:10px 11px;
        overflow-wrap:anywhere;
        word-break:break-word;
    }

    .protection-devices-wrap .check-table td:nth-child(1){
        grid-column:1;
        border-right:1px solid #edf0f4!important;
    }

    .protection-devices-wrap .check-table td:nth-child(2){
        grid-column:1 / -1;
        grid-row:2;
        background:#fbfcfe;
    }

    .protection-devices-wrap .check-table td:nth-child(3){
        grid-column:2;
        grid-row:1;
    }

    .protection-devices-wrap .check-table td:nth-child(4){
        grid-column:1 / -1;
        grid-row:3;
    }

    .protection-devices-wrap .check-table td:nth-child(5){
        grid-column:1 / -1;
        grid-row:4;
        border-bottom:0!important;
    }

    .protection-devices-wrap .check-table td:nth-child(1)::before,
    .protection-devices-wrap .check-table td:nth-child(3)::before,
    .protection-devices-wrap .check-table td:nth-child(4)::before,
    .protection-devices-wrap .check-table td:nth-child(5)::before{
        display:block;
        margin-bottom:5px;
        color:#667085;
        font-size:8px;
        line-height:1.2;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.55px;
    }

    .protection-devices-wrap .check-table td:nth-child(1)::before{content:"ID";}
    .protection-devices-wrap .check-table td:nth-child(3)::before{content:"Priority";}
    .protection-devices-wrap .check-table td:nth-child(4)::before{content:"Result";}
    .protection-devices-wrap .check-table td:nth-child(5)::before{content:"Evidence";}

    .protection-devices-wrap .check-table td:nth-child(2)::before{
        content:"Functional Test";
        display:block;
        margin-bottom:5px;
        color:#667085;
        font-size:8px;
        line-height:1.2;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.55px;
    }

    .protection-devices-wrap .result-group{
        width:100%;
        gap:5px;
    }

    .protection-devices-wrap .result-pill{
        flex:0 0 auto;
    }

    .protection-devices-wrap .result-pill label{
        min-width:38px;
        min-height:32px;
        padding:6px 9px;
    }

    .protection-devices-wrap .evidence-box{
        width:100%;
        box-sizing:border-box;
    }

    .protection-devices-wrap input[type="file"]{
        max-width:100%;
        box-sizing:border-box;
    }
}

@media(max-width:380px){
    .protection-devices-wrap .check-table td{
        padding:9px;
    }

    .protection-devices-wrap .result-pill label{
        min-width:35px;
        padding:5px 7px;
        font-size:9px;
    }
}

/* ---------- PRINT ---------- */
@media print{
    .sat-page{
        padding:0;
        background:#fff;
    }

    .sat-head,
    .sat-card,
    .rule-box{
        box-shadow:none!important;
    }

    .action-bar{
        display:none!important;
    }

    .matrix-wrap{
        overflow:visible;
    }

    .matrix-table,
    .check-table{
        min-width:0;
    }

    .sat-card{
        break-inside:avoid;
    }
}


/* =========================================================
   RESPONSIVE FINAL OVERRIDES
   ---------------------------------------------------------
   Responsive-only changes. SAT logic / field names / PHP
   workflow / checkpoint data remain unchanged.
========================================================= */
html,body{
    max-width:100%;
    overflow-x:hidden;
}

.sat-page,
.sat-wrap,
.sat-card,
.card-bodyx,
.card-head{
    min-width:0;
}

/* Tablet */
@media (max-width: 991.98px){
    .sat-page{
        padding:18px 14px 30px;
    }

    .sat-head{
        padding:20px;
    }

    .sat-head > .d-flex{
        align-items:flex-start!important;
    }

    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .card-head{
        align-items:flex-start;
    }

    .card-head > div:first-child{
        min-width:0;
        flex:1 1 320px;
    }

    .card-help{
        max-width:none;
    }

    .section-tools{
        flex:1 1 100%;
        width:100%;
        margin-left:0;
    }

    .section-badge{
        flex:0 0 auto;
    }

    .accept-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .final-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .signature-box:last-child{
        grid-column:1 / -1;
    }

    .matrix-wrap{
        width:100%;
        max-width:100%;
        overflow-x:auto;
        overflow-y:hidden;
        -webkit-overflow-scrolling:touch;
    }

    .check-table{
        min-width:900px;
    }

    .matrix-table{
        min-width:900px;
    }

    .crosslock{
        overflow:hidden;
    }

    .crosslock .card-bodyx{
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }

    .crosslock-table{
        min-width:600px;
    }
}

/* Mobile */
@media (max-width: 767.98px){
    .sat-page{
        padding:10px 8px 22px;
    }

    .sat-head{
        padding:15px;
        border-radius:13px;
        margin-bottom:14px;
    }

    .sat-head h3{
        font-size:18px;
        line-height:1.35;
    }

    .sat-head p{
        font-size:11px;
        line-height:1.55;
    }

    .sat-head .text-end{
        width:100%;
        padding:9px 11px;
        text-align:left!important;
    }

    .sat-steps{
        gap:6px;
        margin-top:16px;
        padding-top:13px;
    }

    .sat-step{
        font-size:9px;
    }

    .sat-step > span:not(.sat-num){
        display:none;
    }

    .sat-num{
        width:27px;
        height:27px;
        flex-basis:27px;
    }

    /* Keep job information as two columns on mobile. */
    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:7px;
        margin-top:14px;
    }

    .job-box{
        min-height:62px;
        padding:9px 10px;
        border-radius:9px;
    }

    .job-label{
        font-size:8px;
        letter-spacing:.45px;
    }

    .job-value{
        margin-top:4px;
        font-size:11px;
        line-height:1.35;
        overflow-wrap:anywhere;
        word-break:break-word;
    }

    .rule-box{
        margin-bottom:13px;
        padding:11px 12px;
        font-size:10px;
        line-height:1.55;
    }

    .legend{
        gap:5px;
    }

    .legend span{
        min-height:25px;
        padding:4px 7px;
        font-size:9px;
    }

    .sat-card{
        margin-bottom:13px;
        border-radius:11px;
    }

    .card-head{
        min-height:0;
        padding:11px 12px;
        gap:8px;
    }

    .card-title{
        font-size:13px;
        line-height:1.4;
    }

    .card-help{
        margin-top:3px;
        font-size:9px;
        line-height:1.45;
    }

    .card-bodyx{
        padding:10px;
    }

    .section-tools{
        display:grid;
        grid-template-columns:auto repeat(4,minmax(0,1fr));
        width:100%;
        gap:5px;
        align-items:stretch;
    }

    .select-all-label{
        display:flex;
        align-items:center;
        font-size:8px;
    }

    .select-all-result{
        width:100%;
        min-width:0;
        min-height:30px;
        padding:5px 4px;
        font-size:9px;
    }

    .section-badge{
        min-width:34px;
        padding:4px 8px;
        font-size:9px;
    }

    .check-table,
    .matrix-table{
        font-size:10px;
    }

    .check-table th,
    .check-table td,
    .matrix-table th,
    .matrix-table td{
        padding:8px 9px;
    }

    .check-table th,
    .matrix-table th{
        font-size:8px;
    }

    .check-table td,
    .matrix-table td{
        font-size:10px;
    }

    .ref{
        font-size:10px;
    }

    .priority{
        font-size:9px;
    }

    .result-group{
        gap:4px;
    }

    .result-pill label{
        min-width:34px;
        min-height:31px;
        padding:5px 7px;
        font-size:9px;
    }

    .form-control-sm,
    .form-select-sm{
        min-height:32px;
        font-size:10px;
    }

    textarea.form-control-sm{
        min-height:58px;
    }

    .conditional-fields,
    .matrix-conditional,
    .evidence-box{
        padding:8px;
        margin-top:7px;
    }

    .conditional-label,
    .evidence-box label{
        font-size:8px;
    }

    .evidence-box input[type=file]{
        font-size:9px;
    }

    .evidence-box input[type=file]::file-selector-button{
        font-size:9px;
        padding:6px 8px;
    }

    .accept-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
    }

    .accept-box{
        padding:10px;
        border-radius:9px;
    }

    .accept-label{
        margin-bottom:7px;
        font-size:9px;
        line-height:1.35;
    }

    .overall-box{
        margin-top:9px;
        padding:11px;
        border-radius:9px;
    }

    .overall-title{
        font-size:11px;
    }

    .final-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
    }

    .signature-box{
        padding:10px;
        border-radius:9px;
    }

    .signature-box:last-child{
        grid-column:1 / -1;
    }

    .signature-box label{
        font-size:8px;
        line-height:1.35;
    }

    .crosslock-head{
        padding:11px 12px;
        font-size:11px;
    }

    .crosslock-table{
        min-width:560px;
    }

    .crosslock-table th,
    .crosslock-table td{
        padding:8px 9px;
        font-size:9px;
    }

    .readonly-result{
        padding:4px 7px;
        font-size:8px;
    }

    .action-bar{
        position:static;
        margin-top:4px;
        padding:9px;
        border-radius:10px;
        gap:8px;
    }

    .action-bar > div:first-child{
        width:100%;
    }

    .action-bar .save-note{
        font-size:9px;
    }

    .action-bar > .d-flex{
        display:grid!important;
        grid-template-columns:repeat(2,minmax(0,1fr));
        width:100%;
        gap:6px!important;
    }

    .action-bar .btn{
        width:100%;
        min-height:34px;
        padding:6px 8px;
        font-size:10px;
    }
}

/* Very small phones */
@media (max-width: 380px){
    .sat-page{
        padding-left:5px;
        padding-right:5px;
    }

    .sat-head{
        padding:12px;
    }

    .sat-head h3{
        font-size:16px;
    }

    .job-grid{
        gap:5px;
    }

    .job-box{
        padding:8px;
    }

    .job-value{
        font-size:10px;
    }

    .section-tools{
        grid-template-columns:1fr 1fr 1fr 1fr 1fr;
    }

    .select-all-label{
        grid-column:1 / -1;
        min-height:20px;
    }

    .select-all-result{
        min-height:29px;
    }

    .accept-grid,
    .final-grid{
        gap:6px;
    }

    .result-pill label{
        min-width:31px;
        padding-left:5px;
        padding-right:5px;
    }
}

</style>

<div class="content">
<div class="sat-page">
<div class="sat-wrap">

<div class="sat-head">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <h3><i class="bi bi-speedometer2 me-2"></i> SAT — Satisfactory Acceptance Test</h3>
            <p>CHK-02 Rev.1 — Functional Performance Test. PAT construction / physical checkpoints remain cross-locked and read-only.</p>
        </div>
        <div class="text-end">
            <small class="text-muted">Current Date &amp; Time</small>
            <div class="fw-bold"><?= h((new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('d-m-Y h:i A')) ?> IST</div>
        </div>
    </div>

    <div class="job-grid">
        <div class="job-box"><div class="job-label">Service</div><div class="job-value"><?= h($job['service_code'] . ' — ' . ($job['service_name'] ?: 'SAT')) ?></div></div>
        <div class="job-box"><div class="job-label">Asset</div><div class="job-value"><?= h($job['display_asset_id'] ?: $job['asset_name']) ?></div></div>
        <div class="job-box"><div class="job-label">Equipment Type</div><div class="job-value"><?= h($job['asset_type'] ?: ($job['category'] ?: '—')) ?></div></div>
        <div class="job-box"><div class="job-label">Inspection Date</div><div class="job-value"><?= h(!empty($job['proposed_inspection_date']) ? date('d / m / Y', strtotime((string)$job['proposed_inspection_date'])) : '—') ?></div></div>
        <div class="job-box"><div class="job-label">Total Execution Checkpoints</div><div class="job-value">31</div></div>
        <div class="job-box"><div class="job-label">SAF No.</div><div class="job-value"><?= h($job['saf_no'] ?: '—') ?></div></div>
        <div class="job-box"><div class="job-label">PSGA No.</div><div class="job-value"><?= h($job['psga_no'] ?: '—') ?></div></div>
        <div class="job-box"><div class="job-label">Owner</div><div class="job-value"><?= h($job['owner_name'] ?: '—') ?></div></div>
        <div class="job-box"><div class="job-label">Location</div><div class="job-value"><?= h($job['location'] ?: '—') ?></div></div>
        <div class="job-box"><div class="job-label">Status</div><div class="job-value text-primary"><?= h($jobDisplayStatus) ?></div></div>
    </div>
</div>

<?php if ($pageMessage !== ''): ?>
<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= nl2br(h($pageMessage)) ?></div>
<?php endif; ?>

<?php if ($pageError !== ''): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= nl2br(h($pageError)) ?></div>
<?php endif; ?>

<div class="rule-box">
    <strong><i class="bi bi-shield-check me-1"></i>SAT Result Standard:</strong>
    <div class="legend mt-2">
        <span class="legend-c">C — Pass</span>
        <span class="legend-nc">NC — Fail</span>
        <span class="legend-na">NA — Not Applicable</span>
        <span class="legend-d">CA — Conditional Acceptance</span>
    </div>
    <div><strong>NC:</strong> Photograph OR Video + Engineering Remark are mandatory.</div>
    <div><strong>CA:</strong> Photograph OR Video + Conditional Acceptance Remark / Condition are mandatory.</div>
    
</div>

<form method="post" action="sat-checklist-save.php" enctype="multipart/form-data" id="satChecklistForm" novalidate>
    <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
    <input type="hidden" name="assignment_id" value="<?= (int)$assignmentId ?>">
    <input type="hidden" name="selected_asset_id" value="<?= (int)$selectedAssetId ?>">
    <input type="hidden" name="saf_id" value="<?= (int)$actualSafId ?>">
    <input type="hidden" name="checklist_code" value="CHK-02">
    <input type="hidden" name="checklist_revision" value="Rev.1">
    <input type="hidden" name="module" value="SAT">
    <input type="hidden" name="timezone" value="Asia/Kolkata">
    <input type="hidden" name="save_mode" value="asset" id="satSaveMode">
    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

<?php foreach ($sections as $sectionCode => $section): ?>
<div class="sat-card" data-section="<?= h($sectionCode) ?>">
    <div class="card-head">
        <div>
            <div class="card-title"><?= h($section['title']) ?></div>
            <?php if (!empty($section['help'])): ?><div class="card-help"><?= h($section['help']) ?></div><?php endif; ?>
        </div>
        <div class="section-tools">
            <span class="select-all-label">Select All:</span>
            <?php foreach (['C','NC','NA','CA'] as $selectResult): ?>
                <button
                    type="button"
                    class="select-all-result select-all-<?= h($selectResult) ?>"
                    data-section="<?= h($sectionCode) ?>"
                    data-result="<?= h($selectResult) ?>"
                    title="Select <?= h($selectResult) ?> for every result in this section">
                    <?= h($selectResult) ?>
                </button>
            <?php endforeach; ?>
        </div>
        <?php if (!empty($section['priority'])): ?><span class="section-badge"><?= h($section['priority']) ?></span><?php endif; ?>
    </div>
    <div class="card-bodyx">

<?php if ($section['type'] === 'fixed'): ?>
        <div class="matrix-wrap">
        <table class="check-table">
            <thead>
                <tr>
                    <th style="width:95px">ID</th>
                    <th>Functional Test</th>
                    <th style="width:90px">Priority</th>
                    <th style="width:360px">Result</th>
                    <th style="width:280px">Evidence</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($section['items'] as $item): ?>
<?php
$ref = $item['ref'];
$oldResult = existingResult($savedResults, $ref);
$oldRemark = existingField($savedResults, $ref, 'remark');
$oldJustification = existingField($savedResults, $ref, 'technical_justification');
$ev = $evidenceByRef[$ref] ?? [];
$oldEvidence = savedEvidenceForRef($savedResults, $ref, $ev);
$oldPhoto = $oldEvidence['photo'];
$oldVideo = $oldEvidence['video'];
$oldDocument = $oldEvidence['document'];
$hasOldEvidence = hasSavedEvidence($oldEvidence);
?>
                <tr class="checkpoint-row" data-ref="<?= h($ref) ?>">
                    <td class="ref"><?= h($ref) ?></td>
                    <td><?= h($item['text']) ?></td>
                    <td class="priority"><?= h($item['priority']) ?></td>
                    <td>
                        <div class="result-group">
<?php foreach (['C','NC','NA','CA'] as $code): ?>
                            <div class="result-pill">
                                <input type="radio" id="<?= h($ref) ?>_<?= $code ?>" name="result[<?= h($ref) ?>][result]" value="<?= $code ?>" data-ref="<?= h($ref) ?>" <?= $oldResult === $code ? 'checked' : '' ?>>
                                <label for="<?= h($ref) ?>_<?= $code ?>"><?= $code ?></label>
                            </div>
<?php endforeach; ?>
                        </div>

                        <div class="conditional-fields fixed-remark-field">
                            <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm remark-visible" name="result[<?= h($ref) ?>][remark]" rows="2" maxlength="2000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                        </div>

                        <div class="conditional-fields fixed-ca-field">
                            <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm justification-visible" name="result[<?= h($ref) ?>][technical_justification]" rows="2" maxlength="2000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                        </div>
                    </td>
                    <td>
                        <div class="evidence-box <?= (in_array($oldResult, ['NC','CA'], true) || $hasOldEvidence) ? 'show' : '' ?>" data-evidence-ref="<?= h($ref) ?>" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                            <label>Photograph / Video evidence for NC or CA</label>
                            <input type="file" class="form-control form-control-sm photo-input" name="photo[<?= h($ref) ?>]">
                            <?php if ($oldPhoto !== ''): ?>
                                <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                    <i class="bi bi-image"></i> View Existing Photo
                                </a>
                            <?php endif; ?>
                            <label class="mt-2">Video (optional alternate evidence)</label>
                            <input type="file" class="form-control form-control-sm" name="video[<?= h($ref) ?>]">
                            <?= renderExistingEvidence(['video' => $oldVideo]) ?>
                            <?= renderExistingEvidence(['document' => $oldDocument]) ?>
                        </div>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>

<?php elseif ($section['type'] === 'load_matrix'): ?>
        <div class="matrix-wrap">
        <table class="matrix-table">
            <thead>
                <tr>
                    <th>Parameter</th>
<?php foreach ($loadConditions as $condition): ?><th><?= h($condition) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
<?php foreach ($section['parameters'] as $matrixKey => $label): ?>
                <tr>
                    <td><strong><?= h($label) ?></strong></td>
<?php foreach ($loadConditions as $condition): ?>
<?php
$key = 'A|' . $matrixKey . '|' . $condition;
$old = existingMatrix($savedMatrices, $key);
$oldResult = strtoupper(trim((string)($old['result'] ?? '')));
$oldReading = trim((string)($old['actual_reading'] ?? ''));
$oldRemark = trim((string)($old['remark'] ?? ''));
$oldJustification = trim((string)($old['technical_justification'] ?? ''));
$ev = $evidenceByRef[$key] ?? [];
$oldEvidence = savedEvidenceForRef($savedMatrices, $key, $ev);
$oldPhoto = $oldEvidence['photo']; $oldVideo = $oldEvidence['video']; $oldDocument = $oldEvidence['document'];
$hasOldEvidence = hasSavedEvidence($oldEvidence);
?>
                    <td class="sat-matrix-cell">
                        <input type="number" step="any" class="form-control form-control-sm" name="matrix[A][<?= h($matrixKey) ?>][<?= h($condition) ?>][actual_reading]" value="<?= h($oldReading) ?>" placeholder="Reading">
                        <div class="result-group mt-2">
<?php foreach (['C','NC','NA','CA'] as $code): ?>
                            <div class="result-pill">
                                <input type="radio" id="A_<?= h($matrixKey) ?>_<?= h($condition) ?>_<?= $code ?>" name="matrix[A][<?= h($matrixKey) ?>][<?= h($condition) ?>][result]" value="<?= $code ?>" <?= $oldResult === $code ? 'checked' : '' ?>>
                                <label for="A_<?= h($matrixKey) ?>_<?= h($condition) ?>_<?= $code ?>"><?= $code ?></label>
                            </div>
<?php endforeach; ?>
                        </div>
                        <div class="matrix-conditional matrix-remark-field">
                            <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2 matrix-remark" name="matrix[A][<?= h($matrixKey) ?>][<?= h($condition) ?>][remark]" rows="2" maxlength="1000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                        </div>
                        <div class="matrix-conditional matrix-ca-field">
                            <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2 matrix-justification" name="matrix[A][<?= h($matrixKey) ?>][<?= h($condition) ?>][technical_justification]" rows="2" maxlength="1000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                        </div>
                        <div class="matrix-conditional matrix-photo-field" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                            <label class="conditional-label">Photograph / Video evidence for NC or CA</label>
                            <input type="file" class="form-control form-control-sm mt-2 matrix-photo" name="matrix_photo[A][<?= h($matrixKey) ?>][<?= h($condition) ?>]">
                            <?php if ($oldPhoto !== ''): ?>
                            <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                <i class="bi bi-image"></i> View Existing Photo
                            </a>
                        <?php endif; ?>
                        
                            <?= renderExistingEvidence(['video' => $oldVideo]) ?>
                            <?= renderExistingEvidence(['document' => $oldDocument]) ?>
</div>
                    </td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        </div>

<?php elseif ($section['type'] === 'safety_matrix'): ?>
        <div class="matrix-wrap">
            <table class="matrix-table" style="min-width:700px">
                <thead><tr><th style="width:180px">Test</th><th>0%</th><th>25%</th><th>50%</th><th>75%</th><th>100%</th></tr></thead>
                <tbody>
                    <?php foreach (['safety_gear' => 'Safety Gear Test', 'buffer_compression' => 'Buffer Compression Test'] as $matrixKey => $label): ?>
                    <tr>
                        <td><strong><?= h($label) ?></strong></td>
                        <?php foreach ($loadConditions as $condition): ?>
                        <?php
                        $key = 'E|' . $matrixKey . '|' . $condition;
                        $old = existingMatrix($savedMatrices, $key);
                        $oldResult = strtoupper(trim((string)($old['result'] ?? '')));
                        $oldReading = trim((string)($old['actual_reading'] ?? ''));
                        $oldRemark = trim((string)($old['remark'] ?? ''));
                        $oldJustification = trim((string)($old['technical_justification'] ?? ''));
                        $ev = $evidenceByRef[$key] ?? [];
$oldEvidence = savedEvidenceForRef($savedMatrices, $key, $ev);
$oldPhoto = $oldEvidence['photo']; $oldVideo = $oldEvidence['video']; $oldDocument = $oldEvidence['document'];
$hasOldEvidence = hasSavedEvidence($oldEvidence);
                        ?>
                        <td class="sat-matrix-cell">
                            <div class="result-group">
                                <?php foreach (['C','NC','NA','CA'] as $code): ?>
                                <div class="result-pill">
                                    <input type="radio" id="E_<?= h($matrixKey) ?>_<?= h($condition) ?>_<?= $code ?>" name="matrix[E][<?= h($matrixKey) ?>][<?= h($condition) ?>][result]" value="<?= $code ?>" <?= $oldResult === $code ? 'checked' : '' ?>>
                                    <label for="E_<?= h($matrixKey) ?>_<?= h($condition) ?>_<?= $code ?>"><?= $code ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="number" step="any" class="form-control form-control-sm mt-2" name="matrix[E][<?= h($matrixKey) ?>][<?= h($condition) ?>][actual_reading]" value="<?= h($oldReading) ?>" placeholder="Actual reading">
                            <div class="matrix-conditional matrix-remark-field">
                                <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                                <textarea class="form-control form-control-sm mt-2" name="matrix[E][<?= h($matrixKey) ?>][<?= h($condition) ?>][remark]" rows="2" maxlength="1000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                            </div>
                            <div class="matrix-conditional matrix-ca-field">
                                <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                                <textarea class="form-control form-control-sm mt-2" name="matrix[E][<?= h($matrixKey) ?>][<?= h($condition) ?>][technical_justification]" rows="2" maxlength="1000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                            </div>
                            <div class="matrix-conditional matrix-photo-field" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                                <label class="conditional-label">Photograph / Video evidence for NC or CA</label>
                                <input type="file" class="form-control form-control-sm mt-2" name="matrix_photo[E][<?= h($matrixKey) ?>][<?= h($condition) ?>]">
                                <?php if ($oldPhoto !== ''): ?>
                            <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                <i class="bi bi-image"></i> View Existing Photo
                            </a>
                        <?php endif; ?>
                                <label class="conditional-label mt-2">Video (optional alternate evidence)</label>
                                <input type="file" class="form-control form-control-sm mt-2" name="matrix_video[E][<?= h($matrixKey) ?>][<?= h($condition) ?>]">
                                <?= renderExistingEvidence(['video' => $oldVideo]) ?>
                            <?= renderExistingEvidence(['document' => $oldDocument]) ?>
                            </div>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="mt-3 protection-devices-wrap">
            <h6 class="fw-bold">Protection Devices</h6>
            <div class="protection-devices-table-wrap">
            <table class="check-table">
                <thead><tr><th>ID</th><th>Functional Test</th><th>Priority</th><th>Result</th><th>Evidence</th></tr></thead>
                <tbody>
                <?php foreach ($section['items'] as $item): ?>
                <?php
                $ref=$item['ref'];
                $oldResult=existingResult($savedResults,$ref);
                $oldRemark=existingField($savedResults,$ref,'remark');
                $oldJustification=existingField($savedResults,$ref,'technical_justification');
                $ev=$evidenceByRef[$ref]??[];
$oldEvidence=savedEvidenceForRef($savedResults,$ref,$ev);
$oldPhoto=$oldEvidence['photo']; $oldVideo=$oldEvidence['video']; $oldDocument=$oldEvidence['document'];
$hasOldEvidence=hasSavedEvidence($oldEvidence);
                ?>
                <tr class="checkpoint-row" data-ref="<?= h($ref) ?>">
                    <td class="ref"><?= h($ref) ?></td>
                    <td><?= h($item['text']) ?></td>
                    <td class="priority"><?= h($item['priority']) ?></td>
                    <td>
                        <div class="result-group">
                        <?php foreach(['C','NC','NA','CA'] as $code): ?>
                            <div class="result-pill">
                                <input type="radio" id="<?= h($ref) ?>_<?= $code ?>" name="result[<?= h($ref) ?>][result]" value="<?= $code ?>" data-ref="<?= h($ref) ?>" <?= $oldResult===$code?'checked':'' ?>>
                                <label for="<?= h($ref) ?>_<?= $code ?>"><?= $code ?></label>
                            </div>
                        <?php endforeach; ?>
                        </div>

                        <div class="conditional-fields fixed-remark-field">
                            <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm remark-visible" name="result[<?= h($ref) ?>][remark]" rows="2" maxlength="2000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                        </div>

                        <div class="conditional-fields fixed-ca-field">
                            <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm justification-visible" name="result[<?= h($ref) ?>][technical_justification]" rows="2" maxlength="2000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                        </div>
                    </td>
                    <td>
                        <div class="evidence-box <?= (in_array($oldResult, ['NC','CA'], true) || $hasOldEvidence) ? 'show' : '' ?>" data-evidence-ref="<?= h($ref) ?>" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                            <label>Photograph / Video evidence for NC or CA</label>
                            <input type="file" class="form-control form-control-sm photo-input" name="photo[<?= h($ref) ?>]">
                            <?php if ($oldPhoto !== ''): ?>
                            <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                <i class="bi bi-image"></i> View Existing Photo
                            </a>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>

<?php elseif ($section['type'] === 'simple_matrix'): ?>
        <div class="matrix-wrap">
            <table class="matrix-table" style="min-width:800px">
                <thead><tr><th style="width:190px">Load Condition</th><th><?= h($section['matrix_label']) ?></th><th>Actual Reading / Remark</th><th>Evidence</th></tr></thead>
                <tbody>
                <?php foreach ($loadConditions as $condition): ?>
                <?php
                $key=$sectionCode.'|working|'.$condition;
                $old=existingMatrix($savedMatrices,$key);
                $oldResult=strtoupper(trim((string)($old['result']??'')));
                $oldReading=trim((string)($old['actual_reading']??''));
                $oldRemark=trim((string)($old['remark']??''));
                $oldJustification=trim((string)($old['technical_justification']??''));
                $ev=$evidenceByRef[$key]??[];
$oldEvidence=savedEvidenceForRef($savedMatrices,$key,$ev);
$oldPhoto=$oldEvidence['photo']; $oldVideo=$oldEvidence['video']; $oldDocument=$oldEvidence['document'];
$hasOldEvidence=hasSavedEvidence($oldEvidence);
                ?>
                <tr class="sat-matrix-row">
                    <td><strong><?= h($condition) ?></strong></td>
                    <td>
                        <div class="result-group">
                        <?php foreach(['C','NC','NA','CA'] as $code): ?>
                            <div class="result-pill">
                                <input type="radio" id="<?= h($sectionCode) ?>_<?= h($condition) ?>_<?= $code ?>" name="matrix[<?= h($sectionCode) ?>][working][<?= h($condition) ?>][result]" value="<?= $code ?>" <?= $oldResult===$code?'checked':'' ?>>
                                <label for="<?= h($sectionCode) ?>_<?= h($condition) ?>_<?= $code ?>"><?= $code ?></label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <input type="number" step="any" class="form-control form-control-sm" name="matrix[<?= h($sectionCode) ?>][working][<?= h($condition) ?>][actual_reading]" value="<?= h($oldReading) ?>" placeholder="Actual reading">
                        <div class="matrix-conditional matrix-remark-field">
                            <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2" name="matrix[<?= h($sectionCode) ?>][working][<?= h($condition) ?>][remark]" rows="2" maxlength="1000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                        </div>
                        <div class="matrix-conditional matrix-ca-field">
                            <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2" name="matrix[<?= h($sectionCode) ?>][working][<?= h($condition) ?>][technical_justification]" rows="2" maxlength="1000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                        </div>
                    </td>
                    <td>
                        <div class="matrix-conditional matrix-photo-field" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                            <label class="conditional-label">Photograph / Video evidence for NC or CA</label>
                            <input type="file" class="form-control form-control-sm" name="matrix_photo[<?= h($sectionCode) ?>][working][<?= h($condition) ?>]">
                            <?php if ($oldPhoto !== ''): ?>
                            <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                <i class="bi bi-image"></i> View Existing Photo
                            </a>
                        <?php endif; ?>
                            <label class="conditional-label mt-2">Video (optional alternate evidence)</label>
                            <input type="file" class="form-control form-control-sm mt-2" name="matrix_video[<?= h($sectionCode) ?>][working][<?= h($condition) ?>]">
                            <?= renderExistingEvidence(['video' => $oldVideo]) ?>
                            <?= renderExistingEvidence(['document' => $oldDocument]) ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

<?php elseif ($section['type'] === 'ard_matrix'): ?>
        <div class="matrix-wrap">
            <table class="matrix-table" style="min-width:800px">
                <thead><tr><th>Load Condition</th><th>Rescue Successful</th><th>Actual Reading / Remark</th><th>Evidence</th></tr></thead>
                <tbody>
                <?php foreach ($loadConditions as $condition): ?>
                <?php
                $key='I|ard|'.$condition;
                $old=existingMatrix($savedMatrices,$key);
                $oldResult=strtoupper(trim((string)($old['result']??'')));
                $oldReading=trim((string)($old['actual_reading']??''));
                $oldRemark=trim((string)($old['remark']??''));
                $oldJustification=trim((string)($old['technical_justification']??''));
                $ev=$evidenceByRef[$key]??[];
$oldEvidence=savedEvidenceForRef($savedMatrices,$key,$ev);
$oldPhoto=$oldEvidence['photo']; $oldVideo=$oldEvidence['video']; $oldDocument=$oldEvidence['document'];
$hasOldEvidence=hasSavedEvidence($oldEvidence);
                ?>
                <tr class="sat-matrix-row">
                    <td><strong><?= h($condition) ?></strong></td>
                    <td>
                        <div class="result-group">
                        <?php foreach(['C','NC','NA','CA'] as $code): ?>
                            <div class="result-pill">
                                <input type="radio" id="I_<?= h($condition) ?>_<?= $code ?>" name="matrix[I][ard][<?= h($condition) ?>][result]" value="<?= $code ?>" <?= $oldResult===$code?'checked':'' ?>>
                                <label for="I_<?= h($condition) ?>_<?= $code ?>"><?= $code ?></label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <input type="number" step="any" class="form-control form-control-sm" name="matrix[I][ard][<?= h($condition) ?>][actual_reading]" value="<?= h($oldReading) ?>" placeholder="Actual reading">
                        <div class="matrix-conditional matrix-remark-field">
                            <label class="conditional-label">Engineering Remark <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2" name="matrix[I][ard][<?= h($condition) ?>][remark]" rows="2" maxlength="1000" placeholder="Engineering Remark — REQUIRED for NC"><?= h($oldRemark) ?></textarea>
                        </div>
                        <div class="matrix-conditional matrix-ca-field">
                            <label class="conditional-label">Conditional Acceptance Remark / Condition <span class="required-star">*</span></label>
                            <textarea class="form-control form-control-sm mt-2" name="matrix[I][ard][<?= h($condition) ?>][technical_justification]" rows="2" maxlength="1000" placeholder="Conditional Acceptance Remark — REQUIRED for CA"><?= h($oldJustification) ?></textarea>
                        </div>
                    </td>
                    <td>
                        <div class="matrix-conditional matrix-photo-field" data-has-saved="<?= $hasOldEvidence ? '1' : '0' ?>">
                            <label class="conditional-label">Photograph / Video evidence for NC or CA</label>
                            <input type="file" class="form-control form-control-sm" name="matrix_photo[I][ard][<?= h($condition) ?>]">
                            <?php if ($oldPhoto !== ''): ?>
                            <a href="<?= h(evidenceUrl($oldPhoto)) ?>" target="_blank" rel="noopener" class="existing-evidence-link">
                                <i class="bi bi-image"></i> View Existing Photo
                            </a>
                        <?php endif; ?>
                            <label class="conditional-label mt-2">Video (optional alternate evidence)</label>
                            <input type="file" class="form-control form-control-sm mt-2" name="matrix_video[I][ard][<?= h($condition) ?>]">
                            <?= renderExistingEvidence(['video' => $oldVideo]) ?>
                            <?= renderExistingEvidence(['document' => $oldDocument]) ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="alert alert-secondary mt-3 mb-0">
            <strong>ARD Acceptance Criteria:</strong>
            Rescue Time &lt; 120 Seconds &nbsp; | &nbsp;
            Level Accuracy ±10 mm &nbsp; | &nbsp;
            Door Opening Successful &nbsp; | &nbsp;
            Passenger Release Successful
        </div>
<?php endif; ?>

    </div>
</div>
<?php endforeach; ?>

<!-- SAT ACCEPTANCE RESULT -->
<div class="sat-card">
    <div class="card-head">
        <div>
            <div class="card-title">SAT ACCEPTANCE RESULT</div>
            <div class="card-help">Overall SAT outcome is based on the controlled SAT acceptance result.</div>
        </div>
    </div>
    <div class="card-bodyx">
        <div class="accept-grid">
<?php foreach ([
    'functional_test' => 'Functional Test Completed',
    'load_test' => 'Load Test',
    'rescue_test' => 'Rescue Test',
    'electrical_test' => 'Electrical Test',
] as $key => $label): ?>
            <div class="accept-box">
                <div class="accept-label"><?= h($label) ?></div>
                <div class="result-group">
<?php foreach (['PASS','FAIL'] as $value): ?>
                    <div class="result-pill">
                        <input type="radio" id="accept_<?= h($key) ?>_<?= $value ?>" name="acceptance_result[<?= h($key) ?>]" value="<?= h($value) ?>" <?= strtoupper(trim((string)($existingFinal[$key] ?? ''))) === $value ? 'checked' : '' ?>>
                        <label for="accept_<?= h($key) ?>_<?= $value ?>"><?= $value ?></label>
                    </div>
<?php endforeach; ?>
                </div>
            </div>
<?php endforeach; ?>
        </div>

        <div class="overall-box">
            <div class="overall-title">Overall SAT</div>
            <div class="result-group mt-2">
<?php foreach (['SATISFACTORY','UNSATISFACTORY'] as $value): ?>
                <div class="result-pill">
                    <input type="radio" id="overall_<?= h($value) ?>" name="acceptance_result[overall_sat]" value="<?= h($value) ?>" <?= strtoupper(trim((string)($existingFinal['overall_sat'] ?? ''))) === $value ? 'checked' : '' ?>>
                    <label for="overall_<?= h($value) ?>"><?= h($value) ?></label>
                </div>
<?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- CROSS LOCK -->
<div class="sat-card crosslock">
    <div class="crosslock-head">
        <i class="bi bi-lock-fill me-1"></i>
        CROSS-LOCK ITEMS — READ ONLY
        <div class="small fw-normal mt-1">Inherited from PAT. These items shall not appear as editable SAT checkpoints.</div>
    </div>
    <div class="card-bodyx">
        <table class="crosslock-table">
            <thead><tr><th>Cross-Locked Item</th><th>Source</th><th>PAT Status</th></tr></thead>
            <tbody>
<?php foreach ($crossLockItems as $item): ?>
                <tr>
                    <td><?= h($item) ?></td>
                    <td><span class="readonly-result">PAT</span></td>
                    <td>
                        <?php
                        $patOverall = strtoupper(trim((string)($patChecklist['final_status'] ?? '')));
                        if ($patOverall === '') {
                            $patOverall = strtoupper(trim((string)($patChecklist['overall_status'] ?? '')));
                        }
                        ?>
                        <span class="readonly-result">
                            <?= h($patOverall !== '' ? $patOverall : 'CLOSED / INHERITED') ?>
                        </span>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
        <div class="small text-muted mt-2">Inspector View: Read Only</div>
    </div>
</div>

<!-- ADMIN HIDDEN / CONTROLLED METADATA -->
<input type="hidden" name="standard_clause" value="">
<input type="hidden" name="cross_lock_source" value="PAT">
<input type="hidden" name="closure_method" value="Functional Retest">

<!-- SAT CHECKLIST PROGRESS -->
<div class="nleta-card">
    <div class="nleta-card-header">
        <h4 class="mb-1"><i class="bi bi-bar-chart-line me-2"></i>Checklist Progress</h4>
        <div class="text-muted small"><?= (int)$totalFixedSatCheckpoints ?> active fixed SAT checkpoints + dynamic matrix execution rows</div>
    </div>
    <div class="summary-grid summary-grid-totals">
        <div class="summary-box"><div class="summary-label">Total Execution Checkpoints</div><div class="summary-value summary-total" id="summaryTotal"><?= (int)$totalSatExecutionCheckpoints ?></div></div>
        <div class="summary-box"><div class="summary-label">Total Fixed Points</div><div class="summary-value summary-total" id="summaryFixed"><?= (int)$totalFixedSatCheckpoints ?></div></div>
        <div class="summary-box"><div class="summary-label">Matrix-wise Points</div><div class="summary-value summary-total" id="summaryMatrix"><?= (int)$totalSatMatrixRows ?></div></div>
    </div>
    <div class="summary-grid summary-grid-results">
        <div class="summary-box"><div class="summary-label">C — Complied</div><div class="summary-value summary-c" id="summaryC">0</div></div>
        <div class="summary-box"><div class="summary-label">NC — Non-Complied</div><div class="summary-value summary-nc" id="summaryNC">0</div></div>
        <div class="summary-box"><div class="summary-label">NA — Not Applicable</div><div class="summary-value summary-na" id="summaryNA">0</div></div>
        <div class="summary-box"><div class="summary-label">CA — Conditional Acceptance</div><div class="summary-value summary-ca" id="summaryCA">0</div></div>
    </div>
</div>

<!-- FINAL INSPECTION STATUS -->
<div class="nleta-card">
    <div class="nleta-card-header">
        <h4 class="mb-1"><i class="bi bi-flag me-2"></i>Final Inspection Status</h4>
        <div class="text-muted small">Overall inspection outcome.</div>
    </div>
    <div class="final-status-grid">
        <?php foreach (['PASS','CONDITIONAL PASS','FAIL'] as $status): ?>
        <label class="final-option">
            <input type="radio" name="final_status" value="<?= h($status) ?>" <?= $existingFinalStatus === $status ? 'checked' : '' ?>>
            <?= h($status) ?>
        </label>
        <?php endforeach; ?>
    </div>
    <div class="final-fields">
        <div class="final-field"><label>Inspector Name</label><input type="text" name="signatures[inspector]" value="<?= h($existingSignatures['inspector'] ?? '') ?>" placeholder="Inspector name" maxlength="255"></div>
        <div class="final-field"><label>Owner Representative</label><input type="text" name="signatures[owner]" value="<?= h($existingSignatures['owner'] ?? '') ?>" placeholder="Owner representative" maxlength="255"></div>
        <div class="final-field"><label>AMC Representative</label><input type="text" name="signatures[amc]" value="<?= h($existingSignatures['amc'] ?? '') ?>" placeholder="AMC representative" maxlength="255"></div>
    </div>
</div>

<div class="action-bar">
    <div>
        <strong>CHK-02 Rev.1</strong>
        <div class="save-note">
            <?= (int)$totalFixedSatCheckpoints ?> Fixed + <?= (int)$totalSatMatrixRows ?> Matrix-wise = <?= (int)$totalSatExecutionCheckpoints ?> Total • Evidence persists on server • Cross-locked PAT items read-only
            <span id="satDraftStatus" style="font-weight:700;color:#667085;"> • Draft Auto-save Enabled</span>
            <?php if ($lastSavedAt !== ''): ?> • Last saved: <?= h($lastSavedAt) ?> IST<?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="inspections.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <button type="reset" class="btn btn-outline-warning" id="resetSat"><i class="bi bi-arrow-counterclockwise me-1"></i>Reset</button>
        <button type="submit" class="btn btn-primary" id="saveSat"><i class="bi bi-send-check me-1"></i>Submit Checklist</button>
    </div>
</div>

</form>

</div>
</div>
</div>

<script>
(function(){
    'use strict';

    const form = document.getElementById('satChecklistForm');
    if (!form) return;

    const jobId = String(form.querySelector('input[name="job_id"]')?.value || '');
    const draftKey = 'nleta_sat_draft_' + jobId;

    let autoSaveTimer = null;
    let autoSaveBusy = false;
    let autoSavePending = false;
    let finalSubmit = false;
    let restoringDraft = false;

    const saveModeInput = form.querySelector('input[name="save_mode"]');
    const submitButton = document.getElementById('saveSat');
    const resetButton = document.getElementById('resetSat');
    const draftStatus = document.getElementById('satDraftStatus');

    /* Server-persisted evidence loaded by PHP. File inputs cannot be
       repopulated after refresh/back, so this map is the durable UI state. */
    const savedEvidence = <?= json_encode($evidenceByRef, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};

    function setDraftStatus(message, state){
        if (!draftStatus) return;

        draftStatus.textContent = ' • ' + message;

        if (state === 'saving') {
            draftStatus.style.color = '#b54708';
        } else if (state === 'saved') {
            draftStatus.style.color = '#087443';
        } else if (state === 'error') {
            draftStatus.style.color = '#b42318';
        } else {
            draftStatus.style.color = '#667085';
        }
    }

    function evidenceRefFromInput(input){
        if (!input) return '';

        const name = input.getAttribute('name') || '';
        const row = input.closest('.checkpoint-row');

        if (row && row.dataset.ref) {
            return row.dataset.ref;
        }

        const matrixMatch = name.match(/^matrix_(?:photo|video)\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]$/);

        if (matrixMatch) {
            return matrixMatch[1] + '|' + matrixMatch[2] + '|' + matrixMatch[3];
        }

        return '';
    }

    function evidenceResultForInput(input){
        if (!input) return '';

        const row = input.closest('.checkpoint-row, .sat-matrix-cell, .sat-matrix-row');

        if (!row) return '';

        const checked = row.querySelector('input[type="radio"][name*="[result]"]:checked');

        return checked ? String(checked.value || '').toUpperCase() : '';
    }

    function updateSavedEvidenceUi(reference, type, path){
        if (!reference || !path) return;

        if (!savedEvidence[reference]) {
            savedEvidence[reference] = {
                ref_code: reference,
                result: evidenceResultForInput(
                    form.querySelector('[data-ref="' + CSS.escape(reference) + '"]')
                ),
                photo_path: '',
                video_path: '',
                document_path: ''
            };
        }

        if (type === 'photo') {
            savedEvidence[reference].photo_path = path;
        } else if (type === 'video') {
            savedEvidence[reference].video_path = path;
        }

        const inputs = form.querySelectorAll('input[type="file"]');

        inputs.forEach(function(input){
            if (evidenceRefFromInput(input) !== reference) return;

            const box = input.closest('.evidence-box, .matrix-photo-field');

            if (!box) return;

            box.dataset.hasSaved = '1';

            let status = box.querySelector('.server-evidence-status');

            if (!status) {
                status = document.createElement('div');
                status.className = 'server-evidence-status small text-success';
                box.appendChild(status);
            }

            const url = '../' + String(path).replace(/^\/+/, '');

            status.innerHTML =
                '✓ Evidence saved on server — ' +
                (type === 'photo' ? 'Photograph' : 'Video') +
                ' saved. ' +
                '<a href="' + url.replace(/"/g, '&quot;') +
                '" target="_blank" rel="noopener">View saved file</a>. ' +
                'Select a new file above to replace it.';
        });
    }

    async function uploadSingleEvidence(input){
        const reference = evidenceRefFromInput(input);
        const result = evidenceResultForInput(input);

        if (!reference) {
            throw new Error('Evidence reference could not be determined.');
        }

        if (!['NC','CA'].includes(result)) {
            throw new Error('Select NC or CA before uploading evidence.');
        }

        if (!input.files || !input.files.length) {
            return;
        }

        const file = input.files[0];
        const maxSize = 50 * 1024 * 1024;

        if (file.size > maxSize) {
            throw new Error('Evidence file cannot exceed 50 MB.');
        }

        const type = input.name.indexOf('video[') === 0 || input.name.indexOf('matrix_video[') === 0
            ? 'video'
            : 'photo';

        const fd = new FormData();

        fd.append('job_id', form.querySelector('input[name="job_id"]').value);
        fd.append('assignment_id', form.querySelector('input[name="assignment_id"]').value);
        fd.append('selected_asset_id', form.querySelector('input[name="selected_asset_id"]').value);
        fd.append('saf_id', form.querySelector('input[name="saf_id"]').value);
        fd.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
        fd.append('upload_only', '1');
        fd.append('evidence_type', type);
        fd.append('evidence_ref', reference);
        fd.append('evidence_result', result);
        fd.append('evidence_file', file);

        setDraftStatus('Uploading evidence…', 'saving');

        const response = await fetch('sat-checklist-save.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const data = await response.json().catch(function(){
            throw new Error('Invalid server response.');
        });

        if (!data || data.success !== true) {
            throw new Error(data && data.message ? data.message : 'Evidence upload failed.');
        }

        updateSavedEvidenceUi(reference, type, data.path || '');

        setDraftStatus('✓ Evidence saved on server • refresh/back safe', 'saved');
    }

    function updateFixedRow(row){
        const checked = row.querySelector('input[type="radio"][name*="[result]"]:checked');
        const result = checked ? checked.value : '';

        const evidence = row.querySelector('.evidence-box');
        const remarkField = row.querySelector('.fixed-remark-field');
        const caField = row.querySelector('.fixed-ca-field');
        const remark = row.querySelector('.remark-visible');
        const justification = row.querySelector('.justification-visible');

        if (evidence) evidence.classList.toggle('show', result === 'NC' || result === 'CA' || evidence.dataset.hasSaved === '1');
        if (remarkField) remarkField.classList.toggle('show', result === 'NC');
        if (caField) caField.classList.toggle('show', result === 'CA');

        if (remark) {
            remark.required = false;
            remark.placeholder = result === 'NC'
                ? 'Engineering Remark — REQUIRED for NC'
                : 'Engineering Remark';
        }

        if (justification) {
            justification.required = false;
            justification.placeholder = result === 'CA'
                ? 'Conditional Acceptance Remark — REQUIRED for CA'
                : 'Conditional Acceptance Remark';
        }

        row.classList.remove('table-danger', 'table-warning');
        if (result === 'NC') row.classList.add('table-danger');
        if (result === 'CA') row.classList.add('table-warning');
    }

    function updateMatrixCell(cell){
        if (!cell) return;

        const checked = cell.querySelector('input[type="radio"][name*="[result]"]:checked');
        const result = checked ? checked.value : '';

        const remarkField = cell.querySelector('.matrix-remark-field');
        const caField = cell.querySelector('.matrix-ca-field');
        const photoField = cell.querySelector('.matrix-photo-field');
        const remark = cell.querySelector('textarea[name*="[remark]"]');
        const justification = cell.querySelector('textarea[name*="[technical_justification]"]');
        const photo = cell.querySelector('.matrix-photo');

        if (remarkField) remarkField.classList.toggle('show', result === 'NC');
        if (caField) caField.classList.toggle('show', result === 'CA');
        if (photoField) photoField.classList.toggle('show', result === 'NC' || result === 'CA' || photoField.dataset.hasSaved === '1');

        if (remark) {
            remark.required = false;
            remark.placeholder = result === 'NC'
                ? 'Engineering Remark — REQUIRED for NC'
                : 'Engineering Remark';
        }

        if (justification) {
            justification.required = false;
            justification.placeholder = result === 'CA'
                ? 'Conditional Acceptance Remark — REQUIRED for CA'
                : 'Conditional Acceptance Remark';
        }

        if (photo) photo.required = false;

        cell.classList.remove('table-danger', 'table-warning');
        if (result === 'NC') cell.classList.add('table-danger');
        if (result === 'CA') cell.classList.add('table-warning');
    }

    function updateMatrixRow(row){
        if (!row) return;

        const checked = row.querySelector('input[type="radio"][name*="[result]"]:checked');
        const result = checked ? checked.value : '';

        const remarkField = row.querySelector('.matrix-remark-field');
        const caField = row.querySelector('.matrix-ca-field');
        const photoField = row.querySelector('.matrix-photo-field');
        const remark = row.querySelector('textarea[name*="[remark]"]');
        const justification = row.querySelector('textarea[name*="[technical_justification]"]');
        const photo = row.querySelector('.matrix-photo');

        if (remarkField) remarkField.classList.toggle('show', result === 'NC');
        if (caField) caField.classList.toggle('show', result === 'CA');
        if (photoField) photoField.classList.toggle('show', result === 'NC' || result === 'CA' || photoField.dataset.hasSaved === '1');

        if (remark) {
            remark.required = false;
            remark.placeholder = result === 'NC'
                ? 'Engineering Remark — REQUIRED for NC'
                : 'Engineering Remark';
        }

        if (justification) {
            justification.required = false;
            justification.placeholder = result === 'CA'
                ? 'Conditional Acceptance Remark — REQUIRED for CA'
                : 'Conditional Acceptance Remark';
        }

        if (photo) photo.required = false;

        row.classList.remove('table-danger', 'table-warning');
        if (result === 'NC') row.classList.add('table-danger');
        if (result === 'CA') row.classList.add('table-warning');
    }

    document.querySelectorAll('.checkpoint-row').forEach(function(row){
        updateFixedRow(row);

        row.querySelectorAll('input[type="radio"]').forEach(function(input){
            input.addEventListener('change', function(){
                updateFixedRow(row);
                queueAutoSave();
            });
        });
    });

    document.querySelectorAll('.sat-matrix-cell').forEach(function(cell){
        updateMatrixCell(cell);

        cell.querySelectorAll('input[type="radio"][name*="[result]"]').forEach(function(input){
            input.addEventListener('change', function(){
                updateMatrixCell(cell);
                queueAutoSave();
            });
        });
    });

    document.querySelectorAll('.sat-matrix-row').forEach(function(row){
        updateMatrixRow(row);

        row.querySelectorAll('input[type="radio"][name*="[result]"]').forEach(function(input){
            input.addEventListener('change', function(){
                updateMatrixRow(row);
                queueAutoSave();
            });
        });
    });

    /* =====================================================
       SECTION-LEVEL SELECT ALL
    ===================================================== */
    function selectAllSectionResults(sectionCode, result, button){
        const sectionCard = form.querySelector(
            '.sat-card[data-section="' + CSS.escape(sectionCode) + '"]'
        );

        if (!sectionCard) return;

        const groups = new Map();

        sectionCard.querySelectorAll('input[type="radio"][name*="[result]"]').forEach(function(input){
            if (!groups.has(input.name)) {
                groups.set(input.name, input);
            }
        });

        let changed = 0;

        groups.forEach(function(_, name){
            const target = Array.from(
                sectionCard.querySelectorAll(
                    'input[type="radio"][name="' + CSS.escape(name) + '"]'
                )
            ).find(function(input){
                return input.value === result;
            });

            if (target) {
                if (!target.checked) {
                    target.click();
                    changed++;
                } else {
                    target.dispatchEvent(new Event('change', {bubbles:true}));
                }
            }
        });

        sectionCard.querySelectorAll('.select-all-result').forEach(function(btn){
            btn.classList.toggle('active', btn === button);
        });

        sectionCard.querySelectorAll('.is-invalid').forEach(function(el){
            el.classList.remove('is-invalid');
        });

        if (button) {
            const originalTitle = button.getAttribute('title') || '';

            button.setAttribute(
                'title',
                changed > 0
                    ? result + ' selected for ' + changed + ' result item(s) in this section.'
                    : result + ' is already selected for all result items in this section.'
            );

            window.setTimeout(function(){
                if (document.body.contains(button)) {
                    button.setAttribute('title', originalTitle);
                }
            }, 2200);
        }
    }

    document.querySelectorAll('.select-all-result').forEach(function(button){
        button.addEventListener('click', function(){
            const sectionCode = this.dataset.section || '';
            const result = this.dataset.result || '';

            if (!sectionCode || !result) return;

            const sectionCard = form.querySelector(
                '.sat-card[data-section="' + CSS.escape(sectionCode) + '"]'
            );

            if (!sectionCard) return;

            const groups = new Map();

            sectionCard.querySelectorAll('input[type="radio"][name*="[result]"]').forEach(function(input){
                groups.set(input.name, true);
            });

            if (!groups.size) return;

            const label = result === 'C'
                ? 'Pass (C)'
                : result === 'NC'
                    ? 'Fail (NC)'
                    : result === 'NA'
                        ? 'Not Applicable (NA)'
                        : 'Conditional Acceptance (CA)';

            if (!confirm(
                'Select ' + label +
                ' for all ' + groups.size +
                ' result item(s) in this section?'
            )) {
                return;
            }

            selectAllSectionResults(sectionCode, result, this);
            queueAutoSave(true);
        });
    });

    /* =====================================================
       LOCAL DRAFT
       -----------------------------------------------------
       File inputs are never placed into localStorage.
       Text, number, textarea, select and radio values are.
    ===================================================== */
    function collectLocalDraft(){
        const data = {};

        form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(el){
            const type = (el.type || '').toLowerCase();

            if (type === 'file' || type === 'button' || type === 'submit' || type === 'reset') {
                return;
            }

            if (type === 'radio' || type === 'checkbox') {
                if (el.checked) {
                    data[el.name] = el.value;
                } else if (!(el.name in data)) {
                    data[el.name] = null;
                }
                return;
            }

            data[el.name] = el.value;
        });

        return {
            version: 1,
            job_id: jobId,
            saved_at: new Date().toISOString(),
            fields: data
        };
    }

    function saveLocalDraft(){
        try {
            localStorage.setItem(draftKey, JSON.stringify(collectLocalDraft()));
        } catch (e) {
            /* Local storage can be unavailable/full; server draft still works. */
        }
    }

    function restoreLocalDraft(){
        let raw = null;

        try {
            raw = localStorage.getItem(draftKey);
        } catch (e) {
            return;
        }

        if (!raw) return;

        let draft;

        try {
            draft = JSON.parse(raw);
        } catch (e) {
            return;
        }

        if (!draft || String(draft.job_id || '') !== jobId || !draft.fields) {
            return;
        }

        restoringDraft = true;

        Object.keys(draft.fields).forEach(function(name){
            const value = draft.fields[name];

            const elements = form.querySelectorAll(
                '[name="' + CSS.escape(name) + '"]'
            );

            if (!elements.length) return;

            elements.forEach(function(el){
                const type = (el.type || '').toLowerCase();

                if (type === 'radio') {
                    el.checked = (String(el.value) === String(value));
                } else if (type === 'checkbox') {
                    el.checked = (value !== null && String(el.value) === String(value));
                } else {
                    el.value = value == null ? '' : value;
                }
            });
        });

        document.querySelectorAll('.checkpoint-row').forEach(updateFixedRow);
        document.querySelectorAll('.sat-matrix-cell').forEach(updateMatrixCell);
        document.querySelectorAll('.sat-matrix-row').forEach(updateMatrixRow);

        restoringDraft = false;

        setDraftStatus('Local draft restored • Auto-save Enabled', 'saved');
    }

    /* =====================================================
       SERVER AUTO-DRAFT
       -----------------------------------------------------
       Uses THIS existing sat-checklist-save.php.
       No new PHP endpoint is required.
    ===================================================== */
    function autoSaveDraft(force){
        if (finalSubmit || restoringDraft) return;

        saveLocalDraft();

        if (autoSaveBusy) {
            autoSavePending = true;
            return;
        }

        if (!force) {
            if (autoSaveTimer) {
                clearTimeout(autoSaveTimer);
            }

            autoSaveTimer = setTimeout(function(){
                autoSaveDraft(true);
            }, 900);

            return;
        }

        autoSaveBusy = true;
        autoSavePending = false;

        setDraftStatus('Saving draft…', 'saving');

        const fd = new FormData(form);

        fd.set('save_mode', 'auto_draft');
        fd.set('ajax', '1');
        fd.set('is_draft', '1');

        fetch('sat-checklist-save.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function(response){
            return response.json().catch(function(){
                throw new Error('Invalid server response.');
            });
        })
        .then(function(data){
            if (!data || data.success !== true) {
                throw new Error(
                    data && data.message
                        ? data.message
                        : 'Draft save failed.'
                );
            }

            const timeText = data.saved_at_ist
                ? data.saved_at_ist + ' IST'
                : new Date().toLocaleTimeString('en-IN', {
                    hour:'2-digit',
                    minute:'2-digit',
                    second:'2-digit',
                    hour12:true
                }) + ' IST';

            setDraftStatus('✓ Draft saved automatically at ' + timeText, 'saved');

            /* A successful server draft contains the latest non-file fields.
             * Keep localStorage too, because it restores immediately after
             * a refresh even if the network is temporarily unavailable. */
            saveLocalDraft();
        })
        .catch(function(error){
            console.warn('SAT auto-draft:', error);
            setDraftStatus('Auto-save failed — local draft retained', 'error');
        })
        .finally(function(){
            autoSaveBusy = false;

            if (autoSavePending && !finalSubmit) {
                autoSavePending = false;
                autoSaveDraft(true);
            }
        });
    }

    function queueAutoSave(immediate){
        if (finalSubmit || restoringDraft) return;

        saveLocalDraft();

        if (immediate) {
            autoSaveDraft(true);
        } else {
            autoSaveDraft(false);
        }
    }

    /* Save all editable non-file fields on typing/change. */
    form.querySelectorAll('input[name], textarea[name], select[name]').forEach(function(el){
        const type = (el.type || '').toLowerCase();

        if (type === 'file' || type === 'submit' || type === 'reset' || type === 'button') {
            return;
        }

        el.addEventListener(
            type === 'radio' || type === 'checkbox' || el.tagName === 'SELECT'
                ? 'change'
                : 'input',
            function(){
                queueAutoSave(false);
            }
        );
    });

    /* Evidence is uploaded immediately, one file at a time.
     * This is independent of localStorage/server auto-draft so the
     * actual file remains available after refresh/back. */
    form.querySelectorAll('input[type="file"]').forEach(function(input){
        input.addEventListener('change', async function(){
            if (!input.files || !input.files.length) return;

            input.classList.remove('is-invalid');

            try {
                await uploadSingleEvidence(input);
                input.value = '';
            } catch (error) {
                console.error('SAT evidence upload:', error);
                input.classList.add('is-invalid');
                alert(error && error.message ? error.message : 'Evidence upload failed.');
            }
        });
    });

    /* Restore browser draft AFTER all listeners/functions are ready. */
    restoreLocalDraft();

    /* Initial server draft save is intentionally NOT forced.
     * Existing DB checklist is already rendered by PHP. */

    /* =====================================================
       FINAL SUBMIT VALIDATION
       -----------------------------------------------------
       Auto-draft never replaces final validation.
    ===================================================== */
    function updateSatProgress(){
        const counts = {C:0, NC:0, NA:0, CA:0};
        form.querySelectorAll('input[type="radio"]:checked').forEach(function(input){
            const name = String(input.name || '');
            if (!(name.indexOf('result[') === 0 || name.indexOf('matrix[') === 0)) return;
            const value = String(input.value || '').toUpperCase();
            if (Object.prototype.hasOwnProperty.call(counts, value)) counts[value]++;
        });
        const set = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = String(value); };
        set('summaryTotal', <?= (int)$totalSatExecutionCheckpoints ?>);
        set('summaryFixed', <?= (int)$totalFixedSatCheckpoints ?>);
        set('summaryMatrix', <?= (int)$totalSatMatrixRows ?>);
        set('summaryC', counts.C); set('summaryNC', counts.NC); set('summaryNA', counts.NA); set('summaryCA', counts.CA);
    }
    form.addEventListener('change', function(event){
        if (event.target && event.target.matches('input[type="radio"][name^="result["], input[type="radio"][name^="matrix["]')) updateSatProgress();
    });
    updateSatProgress();

    form.addEventListener('submit', function(event){
        const errors = [];

        document.querySelectorAll('.checkpoint-row').forEach(function(row){
            const ref = row.dataset.ref || 'Checkpoint';
            const selected = row.querySelector(
                'input[type="radio"][name*="[result]"]:checked'
            );

            const result = selected ? selected.value : '';

            if (!result) {
                errors.push(ref + ': Result is required.');
                return;
            }

            if (result === 'NC') {
                const remark = row.querySelector('.remark-visible');
                const photo = row.querySelector('.photo-input');

                if (!remark || !remark.value.trim()) {
                    errors.push(ref + ': Engineering Remark is required for NC.');
                }

                const saved = savedEvidence[ref] || {};
                const hasEvidence =
                    (photo && photo.files && photo.files.length) ||
                    (row.querySelector('input[type="file"][name^="video["]') &&
                     row.querySelector('input[type="file"][name^="video["]').files.length) ||
                    String(saved.photo_path || '').trim() ||
                    String(saved.video_path || '').trim();

                if (!hasEvidence) {
                    errors.push(ref + ': Photograph OR Video evidence is required for NC.');
                }

            }

            if (result === 'CA') {
                const justification = row.querySelector('.justification-visible');

                if (!justification || !justification.value.trim()) {
                    errors.push(
                        ref +
                        ': Conditional Acceptance Remark is required for CA.'
                    );
                }

                const saved = savedEvidence[ref] || {};
                const photoInput = row.querySelector('input[type="file"][name^="photo["]');
                const videoInput = row.querySelector('input[type="file"][name^="video["]');
                const hasEvidence =
                    (photoInput && photoInput.files && photoInput.files.length) ||
                    (videoInput && videoInput.files && videoInput.files.length) ||
                    String(saved.photo_path || '').trim() ||
                    String(saved.video_path || '').trim();

                if (!hasEvidence) {
                    errors.push(ref + ': Photograph OR Video evidence is required for CA.');
                }
            }
        });

        const matrixRadioGroups = {};

        form.querySelectorAll(
            'input[type="radio"][name^="matrix["]'
        ).forEach(function(input){
            matrixRadioGroups[input.name] = true;
        });

        Object.keys(matrixRadioGroups).forEach(function(name){
            if (name.indexOf('[result]') === -1) return;

            const checked = form.querySelector(
                'input[name="' + CSS.escape(name) + '"]:checked'
            );

            if (!checked) {
                errors.push('SAT matrix: Result is required.');
                return;
            }

            const cell = checked.closest('.sat-matrix-cell');
            const row = checked.closest('.sat-matrix-row');
            const context = cell || row;

            if (!context) return;

            const result = checked.value;

            const remark = context.querySelector(
                'textarea[name*="[remark]"]'
            );

            const justification = context.querySelector(
                'textarea[name*="[technical_justification]"]'
            );

            const photo = context.querySelector('.matrix-photo');
            const existing = context.querySelector('.small.text-success');

            const matrixNameMatch = name.match(/^matrix\[([^\]]+)\]\[([^\]]+)\]\[([^\]]+)\]\[result\]$/);
            const matrixRef = matrixNameMatch
                ? matrixNameMatch[1] + '|' + matrixNameMatch[2] + '|' + matrixNameMatch[3]
                : '';

            const matrixSaved = savedEvidence[matrixRef] || {};
            const matrixPhoto = context.querySelector('input[type="file"][name^="matrix_photo["]');
            const matrixVideo = context.querySelector('input[type="file"][name^="matrix_video["]');
            const matrixHasEvidence =
                (matrixPhoto && matrixPhoto.files && matrixPhoto.files.length) ||
                (matrixVideo && matrixVideo.files && matrixVideo.files.length) ||
                String(matrixSaved.photo_path || '').trim() ||
                String(matrixSaved.video_path || '').trim();

            if (result === 'NC') {
                if (!remark || !remark.value.trim()) {
                    errors.push(
                        'SAT matrix: Engineering Remark is required for NC.'
                    );

                    if (remark) remark.classList.add('is-invalid');
                }

                if (!matrixHasEvidence) {
                    errors.push('SAT matrix: Photograph OR Video evidence is required for NC.');
                }

            }

            if (result === 'CA') {
                if (!justification || !justification.value.trim()) {
                    errors.push(
                        'SAT matrix: Conditional Acceptance Remark is required for CA.'
                    );

                    if (justification) justification.classList.add('is-invalid');
                }

                if (!matrixHasEvidence) {
                    errors.push('SAT matrix: Photograph OR Video evidence is required for CA.');
                }
            }
        });

        if (!form.querySelector('input[name="final_status"]:checked')) {
            errors.push('Please select Final Inspection Status.');
        }

        const acceptanceKeys = [
            'functional_test',
            'load_test',
            'rescue_test',
            'electrical_test'
        ];

        acceptanceKeys.forEach(function(key){
            if (
                !form.querySelector(
                    'input[name="acceptance_result[' + key + ']"]:checked'
                )
            ) {
                errors.push(
                    'SAT Acceptance Result: ' +
                    key.replace(/_/g,' ') +
                    ' is required.'
                );
            }
        });

        if (
            !form.querySelector(
                'input[name="acceptance_result[overall_sat]"]:checked'
            )
        ) {
            errors.push('Overall SAT result is required.');
        }

        if (errors.length) {
            event.preventDefault();

            /* Keep the incomplete state locally/server-side as a draft
             * before showing the validation errors. */
            saveLocalDraft();
            autoSaveDraft(true);

            alert(
                'Please correct the following:\n\n• ' +
                errors.slice(0,30).join('\n• ')
            );

            return false;
        }

        /* Valid final submission. Stop background drafts and tell the
         * existing PHP endpoint that this is the real final save. */
        finalSubmit = true;

        if (autoSaveTimer) {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = null;
        }

        if (saveModeInput) {
            saveModeInput.value = 'asset';
        }

        try {
            localStorage.setItem(
                draftKey + '_final_pending',
                String(Date.now())
            );
        } catch (e) {}

        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML =
                '<span class="spinner-border spinner-border-sm me-1"></span>' +
                'Saving SAT...';
        }
    });

    /* =====================================================
       FINAL SAVE CLEANUP
       -----------------------------------------------------
       inspections.php?sat_saved=1 means final save completed.
       Clear local browser draft only then.
    ===================================================== */
    const params = new URLSearchParams(window.location.search);

    if (params.get('sat_saved') === '1') {
        try {
            localStorage.removeItem(draftKey);
            localStorage.removeItem(draftKey + '_final_pending');
        } catch (e) {}
    }

    /* Browser close/refresh fallback. */
    window.addEventListener('beforeunload', function(){
        if (finalSubmit) return;

        saveLocalDraft();

        if (navigator.sendBeacon) {
            try {
                const fd = new FormData(form);
                fd.set('save_mode', 'auto_draft');
                fd.set('ajax', '1');
                fd.set('is_draft', '1');

                navigator.sendBeacon(
                    'sat-checklist-save.php',
                    fd
                );
            } catch (e) {}
        }
    });

    resetButton?.addEventListener('click', function(event){
        if (!confirm('Reset unsaved SAT checklist fields on this page?')) {
            event.preventDefault();
            return;
        }

        try {
            localStorage.removeItem(draftKey);
        } catch (e) {}

        form.querySelectorAll(
            '.conditional-fields, .matrix-conditional'
        ).forEach(function(el){
            el.classList.remove('show');
        });

        setDraftStatus('Draft cleared • Auto-save Enabled', 'saved');
    });

    document.querySelectorAll('.sat-matrix-cell').forEach(updateMatrixCell);
    document.querySelectorAll('.sat-matrix-row').forEach(updateMatrixRow);

})();
</script>

<?php include('../includes/footer.php'); ?>