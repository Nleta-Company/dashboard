<?php
/* =========================================================
   NLETA INSPECTOR — SAF / PAT CHECKLIST
   CHK-01 — PAT MASTER CHECKLIST
   Revision: Rev.1

   SOURCE OF TRUTH:
   PAT Checklist_01_Rev.1.docx

   FIXED MASTER CHECKPOINTS:
   PAT-001 -> PAT-131

   SECTION D:
   - D1 Common: PAT-022 -> PAT-025
   - D2 Dynamic Per Floor Landing Checklist
   - D3 Ground Floor Only: PAT-026 -> PAT-027

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   MANDATORY RULES:
   NC -> Photograph REQUIRED
   NC -> Engineering Remark REQUIRED
   CA -> Technical Justification REQUIRED

   RECHECK WORKFLOW:
   One recheck only. Recheck is handled separately.

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

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$inspectorId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);
$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);

if ($inspectorId <= 0) {
    header('Location: dashboard.php');
    exit;
}

if ($jobId <= 0) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   LOAD JOB + ASSET FLOOR DATA
========================================================= */

$sql = "
    SELECT
        mj.id AS job_id,
        mj.saf_id,
        mj.service_id,
        s.request_id AS request_id,
        mj.asset_id,
        mj.owner_id,
        mj.status AS job_status,
        mj.job_type,
        mj.checklist AS saved_checklist,

        ia.id AS assignment_id,
        ia.inspector_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,

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
        ia.job_id = ?
        AND ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die('<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">CHK-01 Database Error</h3>'
        . '<strong>Prepare failed:</strong> ' . h($conn->error)
        . '</div>');
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die('<div style="margin:30px;padding:20px;border:1px solid #dc3545;'
        . 'border-radius:10px;background:#fff5f5;font-family:Arial">'
        . '<h3 style="color:#b02a37">CHK-01 Database Error</h3>'
        . '<strong>Execute failed:</strong> ' . h($stmt->error)
        . '</div>');
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}

if (
    trim((string)($job['saf_status'] ?? '')) !== '' &&
    strcasecmp(trim((string)$job['saf_status']), 'Accepted') !== 0
) {
    die('This SAF has not been accepted by the Owner.');
}

$serviceCode = strtoupper(trim((string)($job['service_code'] ?? '')));

if ($serviceCode !== '' && $serviceCode !== 'PAT') {
    die('This checklist is configured for PAT service only.');
}

/* =========================================================
   MULTI-ASSET SAF — LOAD ALL ASSET CHECKLIST INSTANCES
   ---------------------------------------------------------
   IMPORTANT:
   • This does NOT change any checklist checkpoint.
   • One maintenance job/checklist instance = one asset.
   • All assets remain under the SAME SAF / PSGA.
   • Clicking an asset opens that asset's own job_id.
========================================================= */

$multiAssetRows = [];
$multiAssetTotal = 0;
$multiAssetCompleted = 0;
$currentAssetId = (int)($job['asset_id'] ?? 0);
$currentSafId = (int)($job['saf_id'] ?? 0);

if ($currentSafId > 0) {
    $assetListStmt = $conn->prepare("
        SELECT
            mj.id AS job_id,
            mj.asset_id,
            mj.status AS job_status,
            ia.id AS assignment_id,
            ia.status AS assignment_status,
            a.display_asset_id,
            a.asset_name,
            a.location,
            mj.checklist
        FROM maintenance_jobs mj
        INNER JOIN inspector_assignments ia
            ON ia.job_id = mj.id
            AND ia.inspector_id = ?
        LEFT JOIN assets a
            ON a.id = mj.asset_id
        WHERE
            mj.saf_id = ?
            AND mj.job_type = 'saf_inspection'
        ORDER BY
            mj.asset_id ASC,
            mj.id ASC
    ");

    if ($assetListStmt) {
        $assetListStmt->bind_param(
            'ii',
            $inspectorId,
            $currentSafId
        );

        if ($assetListStmt->execute()) {
            $assetListResult = $assetListStmt->get_result();

            while ($assetRow = $assetListResult->fetch_assoc()) {
                $assetChecklist = [];

                if (!empty($assetRow['checklist'])) {
                    $decodedAssetChecklist = json_decode(
                        (string)$assetRow['checklist'],
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

                $assetCompleted = in_array(
                    $assetFinalStatus,
                    ['PASS', 'CONDITIONAL PASS', 'FAIL'],
                    true
                );

                $assetRow['completed'] = $assetCompleted;
                $assetRow['final_status'] = $assetFinalStatus;

                $multiAssetRows[] = $assetRow;
            }
        }

        $assetListStmt->close();
    }
}

/*
 * Legacy / safety fallback:
 * If the SAF has no multi-asset job rows, keep the current
 * single asset visible rather than breaking the checklist page.
 */
if (!$multiAssetRows) {
    $multiAssetRows[] = [
        'job_id' => $jobId,
        'asset_id' => $currentAssetId,
        'job_status' => $job['job_status'] ?? '',
        'assignment_id' => (int)($job['assignment_id'] ?? 0),
        'assignment_status' => $job['assignment_status'] ?? '',
        'display_asset_id' => $job['display_asset_id'] ?? '',
        'asset_name' => $job['asset_name'] ?? '',
        'location' => $job['location'] ?? '',
        'checklist' => $job['saved_checklist'] ?? '',
        'completed' => false,
        'final_status' => ''
    ];
}

$multiAssetTotal = count($multiAssetRows);

foreach ($multiAssetRows as $assetRow) {
    if (!empty($assetRow['completed'])) {
        $multiAssetCompleted++;
    }
}

$allAssetsCompleted =
    $multiAssetTotal > 0 &&
    $multiAssetCompleted === $multiAssetTotal;

/* =========================================================
   SITE ATTENDANCE OTP SERVER-SIDE GUARD
========================================================= */

$attendanceVerified = false;

$attendanceCheck = $conn->prepare("
    SELECT verified_at
    FROM inspector_attendance
    WHERE
        assignment_id = ?
        AND job_id = ?
        AND inspector_id = ?
        AND owner_id = ?
    LIMIT 1
");

if ($attendanceCheck) {
    $assignmentIdForAttendance = (int)($job['assignment_id'] ?? 0);
    $ownerIdForAttendance = (int)($job['owner_id'] ?? 0);

    $attendanceCheck->bind_param(
        'iiii',
        $assignmentIdForAttendance,
        $jobId,
        $inspectorId,
        $ownerIdForAttendance
    );

    if ($attendanceCheck->execute()) {
        $attendanceRow = $attendanceCheck->get_result()->fetch_assoc();
        $attendanceVerified = !empty($attendanceRow['verified_at']);
    }

    $attendanceCheck->close();
}

if (!$attendanceVerified) {
    $_SESSION['error'] =
        'Owner site attendance OTP is not verified. Please complete attendance verification first.';
    header(
        'Location: inspections.php'
    );
    exit;
}

/* =========================================================
   CHK-01 REV.1 MASTER — EXACT DOCX CHECKPOINTS
========================================================= */

$sections = [
    'A' => [
        'title' => 'SECTION A — SHAFT',
        'items' => [
            ['PAT-001', 'Shaft pit constructed as approved drawing', 'P1'],
            ['PAT-002', 'Headroom available as per GAD', 'P1'],
            ['PAT-003', 'Shaft ventilation provided', 'P3'],
            ['PAT-004', 'Shaft lighting installed and operational', 'P2'],
        ],
    ],
    'B' => [
        'title' => 'SECTION B — SHAFT EQUIPMENT',
        'items' => [
            ['PAT-005', 'Shaft white wash completed', 'P4'],
            ['PAT-006', 'Guide rail fish plate bolts tightened', 'P1'],
            ['PAT-007', 'Electrical wiring properly secured', 'P2'],
            ['PAT-008', 'Wiring clear from moving parts', 'P1'],
            ['PAT-009', 'Shaft protected against rainwater ingress', 'P2'],
            ['PAT-010', 'Main & Counterweight guide rails match GAD', 'P1'],
        ],
    ],
    'C' => [
        'title' => 'SECTION C — PIT',
        'items' => [
            ['PAT-011', 'OSG distance from pit floor verified', 'P1'],
            ['PAT-012', 'Pit electrical wiring satisfactory', 'P2'],
            ['PAT-013', 'Buffers installed as per GAD', 'P1'],
            ['PAT-014', 'Car run-by within permissible limit', 'P1'],
            ['PAT-015', 'Counterweight run-by verified', 'P1'],
            ['PAT-016', 'Pit ladder installed correctly', 'P2'],
            ['PAT-017', 'Oil collectors leak proof', 'P3'],
            ['PAT-018', 'Pit cleanliness / painting / marking', 'P3'],
            ['PAT-019', 'Pit lighting operational', 'P2'],
            ['PAT-020', 'Water drainage provision available', 'P2'],
            ['PAT-021', 'Travelling cable clearance adequate', 'P2'],
        ],
    ],
    'D' => [
        'title' => 'SECTION D — LANDING DOOR',
        'items' => [
            ['PAT-022', 'Landing doors aligned in vertical line', 'P1'],
            ['PAT-023', 'Landing doors connected in safety circuit', 'P1'],
            ['PAT-024', 'Toe guard installed below landing door', 'P1'],
            ['PAT-025', 'Landing door electrical wiring satisfactory', 'P2'],
        ],
    ],
    'E' => [
        'title' => 'SECTION E — CAR FRAME',
        'items' => [
            ['PAT-028', 'Isolation pads provided', 'P2'],
            ['PAT-029', 'OEM nameplate with design life', 'P3'],
            ['PAT-030', 'Diverter pulley aligned & guarded', 'P1'],
            ['PAT-031', 'Safety gear with valid certificate', 'P1'],
            ['PAT-032', 'Safety gear linkage connected', 'P1'],
            ['PAT-033', 'Earthing provided', 'P2'],
            ['PAT-034', 'Overload cell operational', 'P1'],
            ['PAT-035', 'Electrical safety switch functional', 'P1'],
        ],
    ],
    'F' => [
        'title' => 'SECTION F — DRIVE SYSTEM',
        'items' => [
            ['PAT-036', 'VVVF drive certified', 'P2'],
            ['PAT-037', 'Emergency brake lever available', 'P1'],
            ['PAT-038', 'Fasteners secured', 'P2'],
            ['PAT-039', 'Electrical wiring satisfactory', 'P2'],
            ['PAT-040', 'Earthing continuity verified', 'P1'],
            ['PAT-041', 'RCCB / ELCB installed', 'P1'],
            ['PAT-042', 'Correct rating verified', 'P2'],
            ['PAT-043', 'Tripping function tested', 'P1'],
        ],
    ],
    'G' => [
        'title' => 'SECTION G — CONTROLLER',
        'items' => [
            ['PAT-044', 'Controller securely installed', 'P2'],
            ['PAT-045', 'Internal wiring satisfactory', 'P2'],
            ['PAT-046', 'Earthing connected', 'P1'],
            ['PAT-047', 'Controller door earthed', 'P1'],
            ['PAT-048', 'Maintenance switch tested', 'P1'],
            ['PAT-049', 'Stop switch tested', 'P1'],
            ['PAT-050', 'Lockable controller with danger label', 'P2'],
            ['PAT-051', 'Wiring diagram available', 'P3'],
            ['PAT-052', 'Diagnostic keypad / HHT available', 'P3'],
        ],
    ],
    'H' => [
        'title' => 'SECTION H — OVERSPEED GOVERNOR',
        'items' => [
            ['PAT-053', 'OSG pulley alignment', 'P1'],
            ['PAT-054', 'Nameplate readable', 'P3'],
            ['PAT-055', 'Test certificate available', 'P2'],
            ['PAT-056', 'Tripping speed verified', 'P1'],
            ['PAT-057', 'Tension weight & safety switch', 'P1'],
            ['PAT-058', 'OSG rope clean without lubrication', 'P3'],
            ['PAT-059', 'Pit pulley clearance adequate', 'P2'],
        ],
    ],
    'I' => [
        'title' => 'SECTION I — CABIN',
        'items' => [
            ['PAT-060', 'Guide shoes satisfactory', 'P2'],
            ['PAT-061', 'All fasteners secured', 'P2'],
            ['PAT-062', 'Oil cups installed', 'P3'],
            ['PAT-063', 'Emergency hooter operational', 'P1'],
            ['PAT-064', 'Handrail securely fixed', 'P3'],
            ['PAT-065', 'Cabin lighting operational', 'P3'],
            ['PAT-066', 'Car top wiring protected', 'P2'],
            ['PAT-067', 'COP stop button tested', 'P1'],
            ['PAT-068', 'Intercom operational', 'P1'],
            ['PAT-069', 'Cabin wall & ceiling satisfactory', 'P3'],
            ['PAT-070', 'COP buttons operational', 'P3'],
            ['PAT-071', 'Position indicator functional', 'P3'],
            ['PAT-072', 'Capacity plate fixed', 'P3'],
            ['PAT-073', 'CCTV installed', 'P4'],
            ['PAT-074', 'Car top junction box operational', 'P2'],
            ['PAT-075', 'Maintenance switch provided', 'P1'],
            ['PAT-076', 'Inspection UP/DOWN operation verified', 'P1'],
            ['PAT-077', 'Cabin fans operational', 'P3'],
        ],
    ],
    'J' => [
        'title' => 'SECTION J — CAR DOOR',
        'items' => [
            ['PAT-078', 'Car toe guard installed', 'P1'],
            ['PAT-079', 'Door opening / closing adjusted', 'P1'],
            ['PAT-080', 'Fasteners secured', 'P2'],
            ['PAT-081', '3D sensor / photocell working', 'P1'],
            ['PAT-082', 'Sill clearance uniform', 'P2'],
            ['PAT-083', 'Entrance dimensions verified', 'P2'],
            ['PAT-084', 'Car door gap within limit', 'P2'],
            ['PAT-085', 'Lobby free from stumbling obstacles', 'P3'],
            ['PAT-086', 'Intercom connected with security room', 'P2'],
        ],
    ],
    'K' => [
        'title' => 'SECTION K — COUNTERWEIGHT',
        'items' => [
            ['PAT-087', 'Counterweight balanced at 50% load', 'P1'],
            ['PAT-088', 'Guide shoes satisfactory', 'P2'],
            ['PAT-089', 'Oil cups installed', 'P3'],
            ['PAT-090', 'Diverter pulley aligned', 'P1'],
            ['PAT-091', 'Weights locked with tie rod', 'P1'],
            ['PAT-092', 'Counterweight safety gear (Hanging Pit)', 'P1'],
            ['PAT-093', 'Counterweight guard provided', 'P2'],
            ['PAT-094', 'Guard height & clearance satisfactory', 'P2'],
        ],
    ],
    'L' => [
        'title' => 'SECTION L — ROPE',
        'items' => [
            ['PAT-095', 'Rope fastening verified', 'P1'],
            ['PAT-096', 'Uniform rope tension', 'P1'],
            ['PAT-097', 'Rope ends secured with bulldog clips', 'P1'],
            ['PAT-098', 'No rope twist', 'P1'],
            ['PAT-099', 'Rope clean without grease', 'P3'],
            ['PAT-100', 'Spring tension equal', 'P2'],
            ['PAT-101', 'Rope test certificates available', 'P2'],
            ['PAT-102', 'Floor level markings completed', 'P3'],
        ],
    ],
    'M' => [
        'title' => 'SECTION M — FINISH',
        'items' => [
            ['PAT-103', 'Landing & cabin cleanliness', 'P4'],
            ['PAT-104', 'Noise within acceptable level', 'P3'],
            ['PAT-105', 'No abnormal vibration', 'P2'],
            ['PAT-106', 'Hoistway condition satisfactory', 'P3'],
        ],
    ],
    'N' => [
        'title' => 'SECTION N — MACHINE ROOM / MRL',
        'items' => [
            ['PAT-107', 'Machine serial matches nameplate', 'P2'],
            ['PAT-108', 'Load capacity matches GAD', 'P1'],
            ['PAT-109', 'Installation matches GAD', 'P1'],
            ['PAT-110', 'Isolation pads provided', 'P2'],
            ['PAT-111', 'Minimum two earthing strips', 'P1'],
            ['PAT-112', 'No abnormal sound', 'P2'],
            ['PAT-113', 'Vibration within range', 'P2'],
            ['PAT-114', 'ELCB provided', 'P1'],
            ['PAT-115', 'Shock treatment chart displayed', 'P3'],
            ['PAT-116', 'Rescue diagram displayed', 'P2'],
            ['PAT-117', 'Caution signage provided', 'P3'],
            ['PAT-118', 'Smoke window provided (MRL only)', 'P2'],
            ['PAT-119', 'Fire extinguisher in Machine Room (MR Lift)', 'P1'],
            ['PAT-120', 'Fire extinguisher beside Top Controller (MRL)', 'P1'],
            ['PAT-121', 'Machine room accessibility safe', 'P2'],
            ['PAT-122', 'Exhaust fan operational', 'P3'],
            ['PAT-123', 'Machine room lighting adequate', 'P2'],
            ['PAT-124', 'Emergency stop provided', 'P1'],
            ['PAT-125', 'Main cable type & size verified', 'P2'],
        ],
    ],
    'O' => [
        'title' => 'SECTION O — DOCUMENTATION',
        'items' => [
            ['PAT-126', 'Log book box installed', 'P3'],
            ['PAT-127', 'Designated maintenance log available', 'P2'],
            ['PAT-128', 'Monthly safety certificate displayed', 'P2'],
            ['PAT-129', 'Valid CAMC / AMC agreement available', 'P3'],
            ['PAT-130', '“NOT IN USE” display available', 'P4'],
            ['PAT-131', 'Valid insurance copy available', 'P2'],
        ],
    ],
];

$groundFloorItems = [
    ['PAT-026', 'Fireman glass & brake hammer available', 'P2'],
    ['PAT-027', 'Fireman switch operational', 'P1'],
];

/* =========================================================
   D2 DYNAMIC PER-FLOOR LANDING CHECKLIST
   These are execution rows and do NOT create PAT-132+ IDs.
========================================================= */

$floorCheckpointDefinitions = [
    'landing_door_alignment' => 'Landing door alignment',
    'landing_door_manual_lock' => 'Landing door manual lock functional',
    'landing_push_button' => 'Landing push button operational',
    'braille_button' => 'Braille button provided',
    'hall_display' => 'Hall display operational',
    'entrance_dimensions' => 'Entrance width & height verified',
    'door_jamb_clearance' => 'Door to jamb clearance within limit'
];

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

        /*
         * DOCX example:
         * B, G, 1, 2, 3 ... Top Floor
         *
         * If the asset has no explicit served_floors list,
         * use the stored number_of_floors as a safe fallback.
         */
        if ($count > 0) {
            $floors[] = 'B';
            $floors[] = 'G';

            for ($i = 1; $i <= $count; $i++) {
                $floors[] = (string)$i;
            }

            $floors[] = 'Top Floor';
        }
    }

    if (!$floors) {
        /*
         * Do not silently invent engineering data.
         * Show a single selectable floor row only when the
         * database has no floor configuration.
         */
        $floors[] = 'G';
    }

    return $floors;
}

$landingFloors = parseServedFloors($job);

/* =========================================================
   COUNT MASTER CHECKPOINTS
========================================================= */

$totalFixedCheckpoints = 0;

foreach ($sections as $section) {
    $totalFixedCheckpoints += count($section['items']);
}

$totalFixedCheckpoints += count($groundFloorItems);

if ($totalFixedCheckpoints !== 131) {
    die('CHK-01 master configuration error. Expected 131 fixed checkpoints, found '
        . $totalFixedCheckpoints . '.');
}

$totalFloorExecutionRows =
    count($landingFloors) * count($floorCheckpointDefinitions);

$totalExecutionCheckpoints =
    $totalFixedCheckpoints + $totalFloorExecutionRows;

/* =========================================================
   LOAD EXISTING CHECKLIST JSON
========================================================= */

$existingChecklist = [];

if (!empty($job['saved_checklist'])) {
    $decoded = json_decode((string)$job['saved_checklist'], true);

    if (is_array($decoded)) {
        $existingChecklist = $decoded;
    }
}

$existingResults =
    is_array($existingChecklist['results'] ?? null)
    ? $existingChecklist['results']
    : [];

$existingFloorResults =
    is_array($existingChecklist['floor_checklist'] ?? null)
    ? $existingChecklist['floor_checklist']
    : [];

$existingSectionRemarks =
    is_array($existingChecklist['section_remarks'] ?? null)
    ? $existingChecklist['section_remarks']
    : [];

$existingGroundRemark =
    (string)($existingChecklist['ground_floor_remark'] ?? '');

$existingFinalStatus =
    strtoupper(trim((string)($existingChecklist['final_status'] ?? '')));

if (!in_array(
    $existingFinalStatus,
    ['PASS', 'CONDITIONAL PASS', 'FAIL'],
    true
)) {
    $existingFinalStatus = '';
}

$existingInspectorSignature =
    (string)($existingChecklist['inspector_signature'] ?? '');

$existingOwnerRepresentative =
    (string)($existingChecklist['owner_representative'] ?? '');

$existingAmcRepresentative =
    (string)($existingChecklist['amc_representative'] ?? '');

$currentIST = date('d/m/Y H:i:s');

/* =========================================================
   HELPERS FOR RESULT DISPLAY
========================================================= */

function savedResult(array $results, string $key): string
{
    $item = $results[$key] ?? [];

    if (is_string($item)) {
        $value = strtoupper(trim($item));
    } elseif (is_array($item)) {
        $value = strtoupper(trim((string)($item['result'] ?? '')));
    } else {
        $value = '';
    }

    return in_array($value, ['C', 'NC', 'NA', 'CA'], true)
        ? $value
        : '';
}

function savedRemark(array $results, string $key): string
{
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['remark'] ?? '')
        : '';
}

function savedJustification(array $results, string $key): string
{
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['technical_justification'] ?? '')
        : '';
}

function savedPhoto(array $results, string $key): string
{
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['photo'] ?? '')
        : '';
}

include('../includes/header.php');
include('../includes/sidebar-inspector.php');
?>

<style>
    .nleta-checklist-page {
        min-height: calc(100vh - 70px);
        padding: 24px;
        background: #f4f6f9
    }

    .nleta-card {
        background: #fff;
        border: 1px solid #e1e5e9;
        border-radius: 14px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, .06);
        margin-bottom: 18px;
        overflow: hidden
    }

    .nleta-card-header {
        padding: 20px 22px;
        border-bottom: 1px solid #e5e7eb
    }

    .page-title {
        margin: 0;
        font-size: 26px;
        font-weight: 800;
        color: #172033
    }

    .page-subtitle {
        margin-top: 5px;
        color: #6c757d
    }

    .controlled-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 11px;
        border-radius: 20px;
        background: #e7f1ff;
        color: #0d6efd;
        font-size: 12px;
        font-weight: 800
    }

    .job-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        padding: 18px
    }

    .job-box {
        background: #f8f9fa;
        border: 1px solid #e3e7eb;
        border-radius: 10px;
        padding: 12px
    }

    .job-label {
        color: #6c757d;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700
    }

    .job-value {
        color: #172033;
        font-weight: 700;
        margin-top: 4px;
        word-break: break-word
    }

    .legend-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 10px;
        padding: 18px
    }

    .legend-item {
        border: 1px solid #e1e5e9;
        border-radius: 10px;
        padding: 13px;
        background: #fff
    }

    .legend-code {
        font-size: 18px;
        font-weight: 900
    }

    .legend-description {
        color: #6c757d;
        font-size: 13px;
        margin-top: 3px
    }

    .legend-c {
        color: #198754
    }

    .legend-nc {
        color: #dc3545
    }

    .legend-na {
        color: #6c757d
    }

    .legend-ca {
        color: #b27a00
    }

    .rule-box {
        margin: 0 18px 18px;
        border: 1px solid #ffe08a;
        background: #fff9df;
        border-radius: 10px;
        padding: 13px 15px;
        color: #624d03;
        font-size: 13px
    }

    .section-heading {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        padding: 14px 18px;
        background: #0d6efd;
        color: #fff
    }

    .section-heading-title {
        font-size: 17px;
        font-weight: 800
    }

    .section-count {
        background: rgba(255, 255, 255, .18);
        border-radius: 20px;
        padding: 5px 10px;
        font-size: 11px;
        font-weight: 800
    }

    .section-bulk-result {
        padding: 12px 18px;
        background: #f8fbff;
        border-bottom: 1px solid #dbe8f7
    }

    .section-bulk-label {
        font-size: 12px;
        font-weight: 800;
        color: #26364d;
        margin-bottom: 8px
    }

    .section-bulk-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 7px
    }

    .bulk-result-btn {
        border: 1px solid #ced4da;
        border-radius: 7px;
        background: #fff;
        padding: 7px 11px;
        font-size: 12px;
        font-weight: 800;
        cursor: pointer;
        transition: .15s
    }

    .bulk-result-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 7px rgba(0, 0, 0, .08)
    }

    .bulk-c {
        color: #198754;
        border-color: #b7dfc8
    }

    .bulk-c:hover {
        background: #eaf8ef
    }

    .bulk-nc {
        color: #dc3545;
        border-color: #f1b7bd
    }

    .bulk-nc:hover {
        background: #fff0f1
    }

    .bulk-na {
        color: #6c757d;
        border-color: #cfd3d7
    }

    .bulk-na:hover {
        background: #f1f3f5
    }

    .bulk-ca {
        color: #b27a00;
        border-color: #ead28a
    }

    .bulk-ca:hover {
        background: #fff9df
    }

    .table-wrap {
        overflow-x: auto
    }

    .checklist-table {
        width: 100%;
        border-collapse: collapse;
        min-width: 1080px
    }

    .checklist-table th {
        background: #f7f8fa;
        border-bottom: 2px solid #dee2e6;
        color: #26364d;
        padding: 11px 10px;
        font-size: 12px;
        font-weight: 800;
        text-transform: uppercase;
        white-space: nowrap
    }

    .checklist-table td {
        padding: 10px;
        border-bottom: 1px solid #edf0f3;
        vertical-align: top;
        font-size: 13px
    }

    .checkpoint-text {
        font-weight: 650;
        color: #202938;
        line-height: 1.45
    }

    .ref-code {
        font-weight: 900;
        color: #172033;
        white-space: nowrap
    }

    .priority {
        display: inline-block;
        min-width: 30px;
        text-align: center;
        padding: 5px 7px;
        border-radius: 6px;
        font-size: 11px;
        font-weight: 900
    }

    .priority-p1 {
        background: #fde2e2;
        color: #a61b1b
    }

    .priority-p2 {
        background: #fff0c2;
        color: #795b00
    }

    .priority-p3 {
        background: #e5f0ff;
        color: #1557a6
    }

    .priority-p4 {
        background: #e7e7e7;
        color: #555
    }

    .result-options {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        min-width: 255px
    }

    .result-option {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 6px 8px;
        border: 1px solid #d8dde3;
        border-radius: 7px;
        background: #fff;
        cursor: pointer;
        font-size: 12px;
        font-weight: 700
    }

    .result-option input {
        margin: 0
    }

    .evidence-area {
        min-width: 270px;
        max-width: 350px
    }

    .result-evidence {
        display: none
    }

    .result-evidence.show {
        display: block
    }

    .dynamic-required {
        display: none
    }

    .dynamic-required.show {
        display: block
    }

    .field-group {
        margin-top: 8px
    }

    .field-group:first-child {
        margin-top: 0
    }

    .field-label {
        display: block;
        font-size: 11px;
        font-weight: 800;
        margin-bottom: 4px
    }

    .required {
        color: #dc3545
    }

    .file-input {
        width: 100%;
        font-size: 12px
    }

    .file-help {
        display: block;
        margin-top: 4px;
        font-size: 10px;
        color: #6c757d
    }

    .remark-input,
    .justification-input {
        width: 100%;
        min-height: 58px;
        resize: vertical;
        border: 1px solid #ced4da;
        border-radius: 7px;
        padding: 8px;
        font-size: 12px
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(5, minmax(0, 1fr));
        gap: 10px;
        padding: 18px
    }

    .summary-box {
        border: 1px solid #e1e5e9;
        border-radius: 10px;
        padding: 14px;
        background: #fff
    }

    .summary-label {
        color: #6c757d;
        font-size: 12px;
        font-weight: 700
    }

    .summary-value {
        margin-top: 4px;
        font-size: 24px;
        font-weight: 900
    }

    .summary-total {
        color: #0d6efd
    }

    .summary-c {
        color: #198754
    }

    .summary-nc {
        color: #dc3545
    }

    .summary-na {
        color: #6c757d
    }

    .summary-ca {
        color: #b27a00
    }

    .final-status-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 10px;
        padding: 18px
    }

    .final-option {
        border: 1px solid #ced4da;
        border-radius: 10px;
        padding: 14px;
        cursor: pointer;
        font-weight: 800;
        background: #fff
    }

    .final-option input {
        margin-right: 7px
    }

    .final-fields {
        padding: 0 18px 18px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px
    }

    .final-field label {
        display: block;
        font-size: 12px;
        font-weight: 800;
        margin-bottom: 5px
    }

    .final-field input {
        width: 100%;
        border: 1px solid #ced4da;
        border-radius: 7px;
        padding: 9px;
        font-size: 13px
    }

    .draft-status {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin-left: 12px;
        font-size: 12px;
        font-weight: 700;
        color: #198754
    }

    .draft-status.saving {
        color: #b27a00
    }

    .draft-status.saved {
        color: #198754
    }

    .draft-status.error {
        color: #dc3545
    }

    .action-bar {
        position: sticky;
        bottom: 0;
        z-index: 20;
        background: rgba(255, 255, 255, .97);
        border-top: 1px solid #dee2e6;
        padding: 14px 18px;
        box-shadow: 0 -3px 12px rgba(0, 0, 0, .05)
    }

    .action-content {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap
    }

    .is-invalid {
        border-color: #dc3545 !important
    }

    .floor-note {
        margin: 0 18px 15px;
        border: 1px solid #cfe2ff;
        background: #f4f8ff;
        border-radius: 10px;
        padding: 12px 14px;
        color: #244b75;
        font-size: 13px
    }

    .floor-title {
        font-weight: 800;
        color: #17395c
    }

    .floor-badge {
        display: inline-block;
        padding: 5px 9px;
        border-radius: 15px;
        background: #e7f1ff;
        color: #0d6efd;
        font-size: 11px;
        font-weight: 800
    }

    .ground-row {
        background: #fffdf2
    }

    .floor-bulk-area {
        margin: 0 18px 15px;
        border: 1px solid #e1e5e9;
        border-radius: 10px;
        background: #fff;
        padding: 14px
    }

    .floor-bulk-heading {
        font-size: 13px;
        font-weight: 900;
        color: #26364d;
        margin-bottom: 4px
    }

    .floor-bulk-help {
        font-size: 10px;
        color: #6c757d;
        line-height: 1.45;
        margin-bottom: 12px
    }

    .floor-bulk-row {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        padding: 9px 0;
        border-top: 1px solid #edf0f3
    }

    .floor-bulk-row:first-of-type {
        border-top: 0
    }

    .floor-bulk-name {
        min-width: 95px
    }

    .floor-bulk-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 7px
    }

    .floor-bulk-result-btn {
        min-width: 135px
    }

    .asset-selector-card {
        margin: 0 0 18px;
        background: #fff;
        border: 1px solid #dfe5ec;
        border-radius: 14px;
        box-shadow: 0 4px 16px rgba(0, 0, 0, .05);
        overflow: hidden
    }

    .asset-selector-header {
        padding: 16px 20px;
        background: #f7faff;
        border-bottom: 1px solid #dbe5f0
    }

    .asset-selector-title {
        font-size: 18px;
        font-weight: 900;
        color: #172033
    }

    .asset-selector-subtitle {
        font-size: 12px;
        color: #6c757d;
        margin-top: 4px
    }

    .asset-progress {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 6px 10px;
        border-radius: 18px;
        background: #e7f1ff;
        color: #0d6efd;
        font-size: 12px;
        font-weight: 900
    }

    .asset-progress.complete {
        background: #eaf8ef;
        color: #198754
    }

    .asset-selector-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
        padding: 16px
    }

    .asset-selector-item {
        border: 2px solid #e2e7ed;
        border-radius: 12px;
        padding: 14px;
        background: #fff;
        transition: .15s
    }

    .asset-selector-item.current {
        border-color: #0d6efd;
        background: #f4f8ff;
        box-shadow: 0 3px 12px rgba(13, 110, 253, .10)
    }

    .asset-selector-item.completed {
        border-color: #b7dfc8;
        background: #f7fff9
    }

    .asset-selector-top {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 8px
    }

    .asset-selector-id {
        font-size: 16px;
        font-weight: 900;
        color: #0d6efd;
        word-break: break-word
    }

    .asset-selector-name {
        margin-top: 5px;
        font-weight: 700;
        color: #26364d
    }

    .asset-selector-location {
        margin-top: 3px;
        font-size: 12px;
        color: #6c757d
    }

    .asset-selector-status {
        font-size: 10px;
        font-weight: 900;
        padding: 5px 8px;
        border-radius: 15px;
        white-space: nowrap
    }

    .asset-status-pending {
        background: #fff3cd;
        color: #856404
    }

    .asset-status-complete {
        background: #d1e7dd;
        color: #0f5132
    }

    .asset-selector-action {
        margin-top: 12px;
        width: 100%
    }

    .final-submit-note {
        margin: 0 18px 18px;
        padding: 13px 15px;
        border-radius: 10px;
        border: 1px solid #cfe2ff;
        background: #f4f8ff;
        color: #244b75;
        font-size: 13px
    }

    .final-submit-note.ready {
        border-color: #b7dfc8;
        background: #f3fff7;
        color: #146c43
    }

    .final-submit-note strong {
        font-weight: 900
    }

    @media(max-width:1000px) {
        .asset-selector-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }
    }

    @media(max-width:650px) {
        .asset-selector-grid {
            grid-template-columns: 1fr
        }
    }

    @media(max-width:1000px) {
        .job-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .summary-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr))
        }

        .final-fields {
            grid-template-columns: 1fr
        }
    }

    @media(max-width:650px) {
        .floor-bulk-name {
            min-width: 100%
        }

        .floor-bulk-result-btn {
            flex: 1 1 140px
        }

        .nleta-checklist-page {
            padding: 10px
        }

        .job-grid,
        .legend-grid {
            grid-template-columns: 1fr
        }

        .summary-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr))
        }

        .final-status-grid {
            grid-template-columns: 1fr
        }

        .page-title {
            font-size: 21px
        }
    }
</style>

<div class="content">
    <div class="nleta-checklist-page">

        <div class="nleta-card">
            <div class="nleta-card-header">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                    <div>
                        <div class="controlled-badge mb-2">
                            <i class="bi bi-shield-check"></i>
                            CHK-01 • REV.1 • CONTROLLED CHECKLIST
                        </div>
                        <h1 class="page-title">NLETA PAT Inspection Checklist</h1>
                        <div class="page-subtitle">Partial Acceptance Test — Master Checklist</div>
                    </div>
                    <div class="text-end">
                        <div class="small text-muted">Current IST</div>
                        <strong><?= h($currentIST) ?></strong>
                    </div>
                </div>
            </div>

            <div class="job-grid">
                <div class="job-box">
                    <div class="job-label">PSGA / Contract No.</div>
                    <div class="job-value"><?= h($job['psga_no'] ?? '—') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">SAF No.</div>
                    <div class="job-value"><?= h($job['saf_no'] ?? '—') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Asset / Lift</div>
                    <div class="job-value"><?= h($job['display_asset_id'] ?: ($job['asset_name'] ?: '—')) ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Owner</div>
                    <div class="job-value"><?= h($job['owner_name'] ?? '—') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Inspector</div>
                    <div class="job-value"><?= h($_SESSION['name'] ?? $_SESSION['full_name'] ?? 'Assigned Inspector') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Location</div>
                    <div class="job-value"><?= h($job['location'] ?? '—') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Service</div>
                    <div class="job-value"><?= h($job['service_name'] ?? 'PAT') ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Equipment Type</div>
                    <div class="job-value"><?= h($job['asset_type'] ?: ($job['category'] ?: 'Lift')) ?></div>
                </div>
                <div class="job-box">
                    <div class="job-label">Inspection Date</div>
                    <div class="job-value">
                        <?php
                        $inspectionDate = (string)($job['proposed_inspection_date'] ?? '');
                        if ($inspectionDate !== '') {
                            $ts = strtotime($inspectionDate);
                            echo $ts !== false ? h(date('d/m/Y', $ts)) : h($inspectionDate);
                        } else {
                            echo '—';
                        }
                        ?>
                    </div>
                </div>
                <div class="job-box">
                    <div class="job-label">Checklist</div>
                    <div class="job-value">CHK-01 Rev.1</div>
                </div>
                <div class="job-box">
                    <div class="job-label">Fixed Checkpoints</div>
                    <div class="job-value">131</div>
                </div>
                <div class="job-box">
                    <div class="job-label">Floor Execution Rows</div>
                    <div class="job-value"><?= (int)$totalFloorExecutionRows ?></div>
                </div>
            </div>
        </div>

        <!-- =======================================================
     MULTI-ASSET SAF CHECKLIST SELECTOR
     The checklist below remains exactly the same.
======================================================= -->
        <div class="asset-selector-card">
            <div class="asset-selector-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <div class="asset-selector-title">
                            <i class="bi bi-layers me-2"></i>SAF Asset Checklists
                        </div>
                        <div class="asset-selector-subtitle">
                            Select a lift/asset below. Each selected asset has its own checklist and saved result under the same SAF.
                        </div>
                    </div>
                    <div class="asset-progress <?= $allAssetsCompleted ? 'complete' : '' ?>">
                        <i class="bi <?= $allAssetsCompleted ? 'bi-check-circle' : 'bi-hourglass-split' ?>"></i>
                        <?= (int)$multiAssetCompleted ?> / <?= (int)$multiAssetTotal ?> Assets Complete
                    </div>
                </div>
            </div>

            <div class="asset-selector-grid">
                <?php foreach ($multiAssetRows as $assetRow): ?>
                    <?php
                    $rowJobId = (int)($assetRow['job_id'] ?? 0);
                    $rowAssignmentId = (int)($assetRow['assignment_id'] ?? 0);
                    $rowAssetId = (int)($assetRow['asset_id'] ?? 0);
                    $rowDisplayId = trim((string)($assetRow['display_asset_id'] ?? ''));
                    $rowAssetName = trim((string)($assetRow['asset_name'] ?? ''));
                    $rowLocation = trim((string)($assetRow['location'] ?? ''));
                    $rowCompleted = !empty($assetRow['completed']);
                    $rowCurrent = ($rowJobId === $jobId);
                    ?>
                    <div class="asset-selector-item <?= $rowCurrent ? 'current' : '' ?> <?= $rowCompleted ? 'completed' : '' ?>">
                        <div class="asset-selector-top">
                            <div>
                                <div class="asset-selector-id">
                                    <?= h($rowDisplayId ?: ('Asset #' . $rowAssetId)) ?>
                                </div>
                                <div class="asset-selector-name">
                                    <?= h($rowAssetName ?: 'Selected Lift / Asset') ?>
                                </div>
                                <?php if ($rowLocation !== ''): ?>
                                    <div class="asset-selector-location">
                                        <i class="bi bi-geo-alt me-1"></i><?= h($rowLocation) ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <span class="asset-selector-status <?= $rowCompleted ? 'asset-status-complete' : 'asset-status-pending' ?>">
                                <?= $rowCompleted ? 'Completed' : 'Pending' ?>
                            </span>
                        </div>

                        <?php if ($rowCurrent): ?>
                            <div class="asset-selector-action">
                                <button type="button" class="btn btn-primary btn-sm w-100" disabled>
                                    <i class="bi bi-pencil-square me-1"></i>Currently Open
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="asset-selector-action">
                                <a
                                    href="saf-checklist.php?assignment_id=<?= $rowAssignmentId ?>&job_id=<?= $rowJobId ?>"
                                    class="btn <?= $rowCompleted ? 'btn-outline-success' : 'btn-outline-primary' ?> btn-sm w-100">
                                    <i class="bi <?= $rowCompleted ? 'bi-eye' : 'bi-list-check' ?> me-1"></i>
                                    <?= $rowCompleted ? 'View Checklist' : 'Open Checklist' ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="final-submit-note <?= $allAssetsCompleted ? 'ready' : '' ?>">
                <?php if ($allAssetsCompleted): ?>
                    <strong><i class="bi bi-check-circle me-1"></i>All <?= (int)$multiAssetTotal ?> selected asset checklists are complete.</strong>
                    You can now submit the complete SAF to the Owner.
                <?php else: ?>
                    <strong><i class="bi bi-info-circle me-1"></i>Complete all selected asset checklists first.</strong>
                    Final SAF submission to the Owner will become available only after every asset is completed.
                <?php endif; ?>
            </div>
        </div>

        <div class="nleta-card">
            <div class="nleta-card-header">
                <h4 class="mb-1"><i class="bi bi-ui-checks-grid me-2"></i>Result Code Standard</h4>
                <div class="text-muted small">According to CHK-01 Rev.1 — select exactly one result for each checkpoint.</div>
            </div>
            <div class="legend-grid">
                <div class="legend-item">
                    <div class="legend-code legend-c">C</div>
                    <div class="legend-description">Complied</div>
                </div>
                <div class="legend-item">
                    <div class="legend-code legend-nc">NC</div>
                    <div class="legend-description">Non-Complied</div>
                </div>
                <div class="legend-item">
                    <div class="legend-code legend-na">NA</div>
                    <div class="legend-description">Not Applicable</div>
                </div>
                <div class="legend-item">
                    <div class="legend-code legend-ca">CA</div>
                    <div class="legend-description">Conditional Acceptance</div>
                </div>
            </div>
            <div class="rule-box">
                <strong>Mandatory Rules:</strong>
                <span class="ms-2">NC → Photograph REQUIRED</span>
                <span class="ms-2">NC → Engineering Remark REQUIRED</span>
                <span class="ms-2">CA → Technical Justification REQUIRED</span>
            </div>
        </div>

        <form method="post" action="saf-checklist-save.php" enctype="multipart/form-data" id="patChecklistForm" novalidate>
            <input type="hidden" name="job_id" value="<?= (int)$jobId ?>">
            <input type="hidden" name="assignment_id" value="<?= (int)($job['assignment_id'] ?? 0) ?>">
            <input type="hidden" name="saf_id" value="<?= (int)$job['saf_id'] ?>">
            <input type="hidden" name="checklist_code" value="CHK-01">
            <input type="hidden" name="checklist_revision" value="Rev.1">
            <input type="hidden" name="module" value="PAT">
            <input type="hidden" name="timezone" value="Asia/Kolkata">
            <input type="hidden" name="save_mode" id="saveMode" value="asset">
            <?php
            if (function_exists('csrfField')) {
                echo csrfField();
            } elseif (function_exists('generateCSRFToken')) {
                echo '<input type="hidden" name="csrf_token" value="' .
                    h(generateCSRFToken()) . '">';
            } else {
                echo '<input type="hidden" name="csrf_token" value="' .
                    h($_SESSION['csrf_token'] ?? '') . '">';
            }
            ?>

            <?php foreach ($sections as $sectionCode => $section): ?>
                <div class="nleta-card section-card">
                    <div class="section-heading">
                        <div class="section-heading-title"><i class="bi bi-list-check me-2"></i><?= h($section['title']) ?></div>
                        <div class="section-count"><?= count($section['items']) ?> Fixed Checkpoints</div>
                    </div>

                    <div class="section-bulk-result">
                        <div class="section-bulk-label">
                            <i class="bi bi-check2-all me-1"></i>Select All Result for this Section:
                        </div>
                        <div class="section-bulk-buttons">
                            <button type="button" class="bulk-result-btn bulk-c" data-section="<?= h($sectionCode) ?>" data-result="C">C — Complied</button>
                            <button type="button" class="bulk-result-btn bulk-nc" data-section="<?= h($sectionCode) ?>" data-result="NC">NC — Non-Complied</button>
                            <button type="button" class="bulk-result-btn bulk-na" data-section="<?= h($sectionCode) ?>" data-result="NA">NA — Not Applicable</button>
                            <button type="button" class="bulk-result-btn bulk-ca" data-section="<?= h($sectionCode) ?>" data-result="CA">CA — Conditional Acceptance</button>
                        </div>
                    </div>

                    <div class="table-wrap">
                        <table class="checklist-table">
                            <thead>
                                <tr>
                                    <th style="width:100px">ID</th>
                                    <th style="width:70px">Priority</th>
                                    <th>Engineering Checkpoint</th>
                                    <th style="width:290px">Result</th>
                                    <th style="width:330px">Evidence / Remark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($section['items'] as $item): ?>
                                    <?php
                                    $ref = $item[0];
                                    $description = $item[1];
                                    $priority = $item[2];
                                    $savedResult = savedResult($existingResults, $ref);
                                    $savedRemark = savedRemark($existingResults, $ref);
                                    $savedJustification = savedJustification($existingResults, $ref);
                                    ?>
                                    <tr data-checkpoint="<?= h($ref) ?>">
                                        <td><span class="ref-code"><?= h($ref) ?></span></td>
                                        <td><span class="priority priority-<?= h(strtolower($priority)) ?>"><?= h($priority) ?></span></td>
                                        <td>
                                            <div class="checkpoint-text"><?= h($description) ?></div>
                                        </td>
                                        <td>
                                            <div class="result-options">
                                                <?php foreach (['C' => 'Complied', 'NC' => 'Non-Complied', 'NA' => 'Not Applicable', 'CA' => 'Conditional Acceptance'] as $code => $label): ?>
                                                    <label class="result-option" title="<?= h($label) ?>">
                                                        <input type="radio" name="result[<?= h($ref) ?>]" value="<?= h($code) ?>" data-ref="<?= h($ref) ?>" <?= $savedResult === $code ? 'checked' : '' ?>>
                                                        <?= h($code) ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="small text-muted mt-2 status-note" data-ref="<?= h($ref) ?>">Select C, NC, NA or CA.</div>
                                        </td>
                                        <td>
                                            <div class="evidence-area">
                                                <div class="result-evidence" data-ref="<?= h($ref) ?>">
                                                    <div class="field-group">
                                                        <label class="field-label">Photograph <span class="required photo-required" data-ref="<?= h($ref) ?>" style="display:none">*</span></label>
                                                        <input type="file" class="form-control form-control-sm file-input photo-input" name="photo[<?= h($ref) ?>]" accept="image/jpeg,image/png,image/jpg,image/webp" data-photo-ref="<?= h($ref) ?>">
                                                        <span class="file-help">NC → Photograph REQUIRED. JPG / JPEG / PNG / WEBP — maximum 3 MB.</span>
                                                    </div>

                                                    <div class="dynamic-required remark-field" data-ref="<?= h($ref) ?>">
                                                        <div class="field-group">
                                                            <label class="field-label">Engineering Remark <span class="required">*</span></label>
                                                            <textarea class="remark-input" name="remark[<?= h($ref) ?>]" data-remark-ref="<?= h($ref) ?>" maxlength="2000" placeholder="Engineering observation..."><?= h($savedRemark) ?></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="dynamic-required justification-field" data-ref="<?= h($ref) ?>">
                                                        <div class="field-group">
                                                            <label class="field-label">Technical Justification <span class="required">*</span></label>
                                                            <textarea class="justification-input" name="technical_justification[<?= h($ref) ?>]" data-justification-ref="<?= h($ref) ?>" maxlength="3000" placeholder="Explain the condition requiring Conditional Acceptance..."><?= h($savedJustification) ?></textarea>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="small text-muted mt-2 status-note" data-ref="<?= h($ref) ?>">Select C, NC, NA or CA.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="p-3">
                        <label class="field-label"><?= h($section['title']) ?> — Section Remark</label>
                        <textarea class="remark-input" name="section_remarks[<?= h($sectionCode) ?>]" maxlength="3000" placeholder="Enter section-level remark..."><?= h($existingSectionRemarks[$sectionCode] ?? '') ?></textarea>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- =======================================================
     SECTION D2 — DYNAMIC PER FLOOR
======================================================= -->
            <div class="nleta-card">
                <div class="section-heading">
                    <div class="section-heading-title"><i class="bi bi-building me-2"></i>SECTION D2 — DYNAMIC PER FLOOR CHECKLIST</div>
                    <div class="section-count"><?= count($landingFloors) ?> Landing(s)</div>
                </div>

                <div class="floor-note">
                    <div class="floor-title mb-1">Landing Door Checklist</div>
                    This table repeats automatically for every landing configured for this asset.
                    The floor rows are execution instances and do not create new PAT IDs.
                    Status options are C / NC / NA / CA.
                </div>

                <div class="floor-bulk-area">
                    <div class="floor-bulk-heading"><i class="bi bi-check2-all me-1"></i>Select All Result Floor-wise</div>
                    <div class="floor-bulk-help">Select a result for one floor to apply it to all 7 landing-door checkpoints on that floor. NC still requires a photograph and engineering remark; CA requires technical justification.</div>
                    <?php foreach ($landingFloors as $floorLabel): ?>
                        <div class="floor-bulk-row" data-floor-bulk="<?= h($floorLabel) ?>">
                            <div class="floor-bulk-name"><span class="floor-badge"><?= h($floorLabel) ?></span></div>
                            <div class="floor-bulk-buttons">
                                <button type="button" class="bulk-result-btn bulk-c floor-bulk-result-btn" data-floor="<?= h($floorLabel) ?>" data-result="C"><i class="bi bi-check-circle me-1"></i>C — Complied</button>
                                <button type="button" class="bulk-result-btn bulk-nc floor-bulk-result-btn" data-floor="<?= h($floorLabel) ?>" data-result="NC"><i class="bi bi-x-circle me-1"></i>NC — Non-Complied</button>
                                <button type="button" class="bulk-result-btn bulk-na floor-bulk-result-btn" data-floor="<?= h($floorLabel) ?>" data-result="NA"><i class="bi bi-dash-circle me-1"></i>NA — Not Applicable</button>
                                <button type="button" class="bulk-result-btn bulk-ca floor-bulk-result-btn" data-floor="<?= h($floorLabel) ?>" data-result="CA"><i class="bi bi-exclamation-circle me-1"></i>CA — Conditional Acceptance</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="table-wrap">
                    <table class="checklist-table floor-table">
                        <thead>
                            <tr>
                                <th style="width:130px">Floor</th>
                                <th>Checkpoint</th>
                                <th style="width:290px">Status</th>
                                <th style="width:330px">Remark / Technical Justification / Photograph</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($landingFloors as $floorIndex => $floorLabel): ?>
                                <?php foreach ($floorCheckpointDefinitions as $floorKey => $floorDescription): ?>
                                    <?php
                                    $savedFloorItem =
                                        is_array($existingFloorResults[$floorLabel][$floorKey] ?? null)
                                        ? $existingFloorResults[$floorLabel][$floorKey]
                                        : [];

                                    $savedFloorResult =
                                        strtoupper(trim((string)($savedFloorItem['result'] ?? '')));

                                    if (!in_array($savedFloorResult, ['C', 'NC', 'NA', 'CA'], true)) {
                                        $savedFloorResult = '';
                                    }

                                    $savedFloorRemark =
                                        (string)($savedFloorItem['remark'] ?? '');

                                    $savedFloorJustification =
                                        (string)($savedFloorItem['technical_justification'] ?? '');
                                    ?>
                                    <tr class="floor-check-row" data-floor="<?= h($floorLabel) ?>" data-floor-key="<?= h($floorKey) ?>">
                                        <?php if ($floorKey === array_key_first($floorCheckpointDefinitions)): ?>
                                            <td rowspan="<?= count($floorCheckpointDefinitions) ?>">
                                                <span class="floor-badge"><?= h($floorLabel) ?></span>
                                            </td>
                                        <?php endif; ?>

                                        <td>
                                            <div class="checkpoint-text"><?= h($floorDescription) ?></div>
                                        </td>

                                        <td>
                                            <div class="result-options">
                                                <?php foreach (['C', 'NC', 'NA', 'CA'] as $code): ?>
                                                    <label class="result-option">
                                                        <input
                                                            type="radio"
                                                            name="floor_result[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                                            value="<?= h($code) ?>"
                                                            data-floor="<?= h($floorLabel) ?>"
                                                            data-floor-key="<?= h($floorKey) ?>"
                                                            <?= $savedFloorResult === $code ? 'checked' : '' ?>>
                                                        <?= h($code) ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </div>
                                            <div class="small text-muted mt-2 floor-status-note">Select C, NC, NA or CA.</div>
                                        </td>

                                        <td>
                                            <div class="evidence-area">
                                                <div class="floor-result-evidence" data-floor="<?= h($floorLabel) ?>" data-floor-key="<?= h($floorKey) ?>" style="display:none">
                                                    <div class="field-group">
                                                        <label class="field-label">Photograph <span class="required floor-photo-required" style="display:none">*</span></label>
                                                        <input
                                                            type="file"
                                                            class="form-control form-control-sm file-input floor-photo-input"
                                                            name="floor_photo[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                                            accept="image/jpeg,image/png,image/jpg,image/webp">
                                                        <span class="file-help">NC → Photograph REQUIRED — maximum 3 MB.</span>
                                                    </div>

                                                    <div class="dynamic-required floor-remark-field">
                                                        <div class="field-group">
                                                            <label class="field-label">Engineering Remark <span class="required">*</span></label>
                                                            <textarea
                                                                class="remark-input floor-remark-input"
                                                                name="floor_remark[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                                                maxlength="2000"><?= h($savedFloorRemark) ?></textarea>
                                                        </div>
                                                    </div>

                                                    <div class="dynamic-required floor-justification-field">
                                                        <div class="field-group">
                                                            <label class="field-label">Technical Justification <span class="required">*</span></label>
                                                            <textarea
                                                                class="justification-input floor-justification-input"
                                                                name="floor_technical_justification[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                                                maxlength="3000"><?= h($savedFloorJustification) ?></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="small text-muted mt-2 floor-status-note">Select C, NC, NA or CA.</div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- =======================================================
     SECTION D3 — GROUND FLOOR ONLY
======================================================= -->
            <div class="nleta-card">
                <div class="section-heading">
                    <div class="section-heading-title"><i class="bi bi-door-open me-2"></i>SECTION D3 — GROUND FLOOR ONLY</div>
                    <div class="section-count"><?= count($groundFloorItems) ?> Checkpoints</div>
                </div>

                <div class="floor-note">
                    Fireman glass / brake hammer and Fireman switch are checked at Ground Floor only,
                    exactly as defined by CHK-01 Rev.1.
                </div>

                <div class="table-wrap">
                    <table class="checklist-table">
                        <thead>
                            <tr>
                                <th style="width:100px">ID</th>
                                <th style="width:70px">Priority</th>
                                <th>Engineering Checkpoint</th>
                                <th style="width:290px">Result</th>
                                <th style="width:330px">Evidence / Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groundFloorItems as $item): ?>
                                <?php
                                $ref = $item[0];
                                $description = $item[1];
                                $priority = $item[2];
                                $savedResult = savedResult($existingResults, $ref);
                                $savedRemark = savedRemark($existingResults, $ref);
                                $savedJustification = savedJustification($existingResults, $ref);
                                ?>
                                <tr class="ground-row" data-checkpoint="<?= h($ref) ?>">
                                    <td><span class="ref-code"><?= h($ref) ?></span></td>
                                    <td><span class="priority priority-<?= h(strtolower($priority)) ?>"><?= h($priority) ?></span></td>
                                    <td>
                                        <div class="checkpoint-text"><?= h($description) ?></div>
                                    </td>
                                    <td>
                                        <div class="result-options">
                                            <?php foreach (['C', 'NC', 'NA', 'CA'] as $code): ?>
                                                <label class="result-option">
                                                    <input type="radio" name="result[<?= h($ref) ?>]" value="<?= h($code) ?>" data-ref="<?= h($ref) ?>" <?= $savedResult === $code ? 'checked' : '' ?>>
                                                    <?= h($code) ?>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="small text-muted mt-2 status-note" data-ref="<?= h($ref) ?>">Select C, NC, NA or CA.</div>
                                    </td>
                                    <td>
                                        <div class="evidence-area">
                                            <div class="result-evidence" data-ref="<?= h($ref) ?>">
                                                <div class="field-group">
                                                    <label class="field-label">Photograph <span class="required photo-required" style="display:none">*</span></label>
                                                    <input type="file" class="form-control form-control-sm file-input photo-input" name="photo[<?= h($ref) ?>]" accept="image/jpeg,image/png,image/jpg,image/webp" data-photo-ref="<?= h($ref) ?>">
                                                    <span class="file-help">NC → Photograph REQUIRED — maximum 3 MB.</span>
                                                </div>
                                                <div class="dynamic-required remark-field" data-ref="<?= h($ref) ?>">
                                                    <div class="field-group">
                                                        <label class="field-label">Engineering Remark <span class="required">*</span></label>
                                                        <textarea class="remark-input" name="remark[<?= h($ref) ?>]" data-remark-ref="<?= h($ref) ?>" maxlength="2000"><?= h($savedRemark) ?></textarea>
                                                    </div>
                                                </div>
                                                <div class="dynamic-required justification-field" data-ref="<?= h($ref) ?>">
                                                    <div class="field-group">
                                                        <label class="field-label">Technical Justification <span class="required">*</span></label>
                                                        <textarea class="justification-input" name="technical_justification[<?= h($ref) ?>]" data-justification-ref="<?= h($ref) ?>" maxlength="3000"><?= h($savedJustification) ?></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="p-3">
                    <label class="field-label">Ground Floor — Section Remark</label>
                    <textarea class="remark-input" name="ground_floor_remark" maxlength="3000"><?= h($existingGroundRemark) ?></textarea>
                </div>
            </div>

            <div class="nleta-card">
                <div class="nleta-card-header">
                    <h4 class="mb-1"><i class="bi bi-bar-chart-line me-2"></i>Checklist Progress</h4>
                    <div class="text-muted small">131 fixed PAT checkpoints + dynamic D2 landing rows</div>
                </div>
                <div class="summary-grid">
                    <div class="summary-box">
                        <div class="summary-label">Total Fixed</div>
                        <div class="summary-value summary-total" id="summaryTotal">131</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">C — Complied</div>
                        <div class="summary-value summary-c" id="summaryC">0</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">NC — Non-Complied</div>
                        <div class="summary-value summary-nc" id="summaryNC">0</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">NA — Not Applicable</div>
                        <div class="summary-value summary-na" id="summaryNA">0</div>
                    </div>
                    <div class="summary-box">
                        <div class="summary-label">CA — Conditional Acceptance</div>
                        <div class="summary-value summary-ca" id="summaryCA">0</div>
                    </div>
                </div>
            </div>

            <div class="nleta-card">
                <div class="nleta-card-header">
                    <h4 class="mb-1"><i class="bi bi-flag me-2"></i>Final Inspection Status</h4>
                    <div class="text-muted small">Overall inspection outcome.</div>
                </div>

                <div class="final-status-grid">
                    <?php foreach (['PASS', 'CONDITIONAL PASS', 'FAIL'] as $status): ?>
                        <label class="final-option">
                            <input type="radio" name="final_status" value="<?= h($status) ?>" <?= $existingFinalStatus === $status ? 'checked' : '' ?>>
                            <?= h($status) ?>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="final-fields">
                    <div class="final-field">
                        <label>Inspector Signature / Name</label>
                        <input type="text" name="inspector_signature" value="<?= h($existingInspectorSignature) ?>" placeholder="Inspector name / signature" maxlength="255">
                    </div>
                    <div class="final-field">
                        <label>Owner Representative</label>
                        <input type="text" name="owner_representative" value="<?= h($existingOwnerRepresentative) ?>" placeholder="Owner representative" maxlength="255">
                    </div>
                    <div class="final-field">
                        <label>AMC Representative</label>
                        <input type="text" name="amc_representative" value="<?= h($existingAmcRepresentative) ?>" placeholder="AMC representative" maxlength="255">
                    </div>
                </div>
            </div>

            <div class="action-bar">
                <div class="action-content">
                    <div>
                        <strong>CHK-01 Rev.1</strong>
                        <span class="text-muted ms-2">131 Fixed Checkpoints</span>
                        <span id="draftStatus" class="draft-status" aria-live="polite"></span>
                    </div>
                    <div>
                        <a href="inspections.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left me-1"></i>Back
                        </a>
                        <button type="reset" class="btn btn-outline-warning me-2" id="resetChecklist">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                        </button>
                        <button type="submit" class="btn btn-primary me-2" id="saveAssetNext">
                            <i class="bi bi-arrow-right-circle me-1"></i>Save Asset &amp; Continue
                        </button>

                        <button
                            type="submit"
                            class="btn btn-success"
                            id="finalSubmitSaf"
                            <?= $allAssetsCompleted ? '' : 'disabled' ?>>
                            <i class="bi bi-send-check me-1"></i>Final Submit Complete SAF to Owner
                        </button>
                    </div>
                </div>
            </div>

        </form>
    </div>
</div>

<script>
    (function() {
        'use strict';

        const form = document.getElementById('patChecklistForm');

        if (!form) return;

        const jobId = form.querySelector('input[name="job_id"]')?.value || '0';
        const safId = form.querySelector('input[name="saf_id"]')?.value || '0';
        const draftKey =
            'NLETA_CHK01_REV1_PAT_DOCX_JOB_' + jobId + '_SAF_' + safId;

        const draftStatus = document.getElementById('draftStatus');
        const saveModeInput = document.getElementById('saveMode');
        const saveAssetNextButton = document.getElementById('saveAssetNext');
        const finalSubmitSafButton = document.getElementById('finalSubmitSaf');

        let saveTimer = null;
        let restoring = false;
        let requestedSubmitMode = 'asset';

        function setDraftStatus(message, type) {
            if (!draftStatus) return;
            draftStatus.textContent = message;
            draftStatus.className = 'draft-status ' + (type || '');
        }

        function esc(value) {
            if (window.CSS && CSS.escape) return CSS.escape(value);
            return String(value).replace(/["\\]/g, '\\$&');
        }

        function getResult(ref) {
            const el = document.querySelector(
                'input[name="result[' + esc(ref) + ']"]:checked'
            );
            return el ? el.value : '';
        }

        function updateFixedCheckpoint(ref) {
            const row = document.querySelector(
                'tr[data-checkpoint="' + esc(ref) + '"]'
            );

            if (!row) return;

            const result = getResult(ref);
            const evidence = row.querySelector('.result-evidence');
            const remarkField = row.querySelector('.remark-field');
            const justificationField = row.querySelector('.justification-field');
            const remark = row.querySelector('textarea[data-remark-ref="' + esc(ref) + '"]');
            const justification = row.querySelector('textarea[data-justification-ref="' + esc(ref) + '"]');
            const photoRequired = row.querySelector('.photo-required');

            if (evidence) evidence.classList.remove('show');
            if (remarkField) remarkField.classList.remove('show');
            if (justificationField) justificationField.classList.remove('show');

            if (remark) {
                remark.required = false;
                remark.classList.remove('is-invalid');
            }

            if (justification) {
                justification.required = false;
                justification.classList.remove('is-invalid');
            }

            if (photoRequired) photoRequired.style.display = 'none';

            row.classList.remove('table-danger', 'table-warning');

            const note = row.querySelector('.status-note');

            if (result === 'C') {
                if (note) {
                    note.textContent = 'C — Complied.';
                    note.className = 'small text-success mt-2 status-note';
                }
                return;
            }

            if (result === 'NA') {
                if (note) {
                    note.textContent = 'NA — Not Applicable.';
                    note.className = 'small text-muted mt-2 status-note';
                }
                return;
            }

            if (result === 'NC') {
                if (evidence) evidence.classList.add('show');
                if (remarkField) remarkField.classList.add('show');
                if (remark) remark.required = true;
                if (photoRequired) photoRequired.style.display = 'inline';
                row.classList.add('table-danger');

                if (note) {
                    note.textContent = 'NC — Photograph and Engineering Remark are mandatory.';
                    note.className = 'small text-danger mt-2 status-note';
                }
                return;
            }

            if (result === 'CA') {
                if (evidence) evidence.classList.add('show');
                if (justificationField) justificationField.classList.add('show');
                if (justification) justification.required = true;
                row.classList.add('table-warning');

                if (note) {
                    note.textContent = 'CA — Technical Justification is mandatory.';
                    note.className = 'small text-warning mt-2 status-note';
                }
                return;
            }

            if (note) {
                note.textContent = 'Select C, NC, NA or CA.';
                note.className = 'small text-muted mt-2 status-note';
            }
        }

        function updateFloorRow(row) {
            if (!row) return;

            const checked = row.querySelector('input[type="radio"]:checked');
            const result = checked ? checked.value : '';

            const evidence = row.querySelector('.floor-result-evidence');
            const remarkField = row.querySelector('.floor-remark-field');
            const justificationField = row.querySelector('.floor-justification-field');
            const remark = row.querySelector('.floor-remark-input');
            const justification = row.querySelector('.floor-justification-input');
            const photoRequired = row.querySelector('.floor-photo-required');
            const note = row.querySelector('.floor-status-note');

            if (evidence) evidence.style.display = 'none';
            if (remarkField) remarkField.classList.remove('show');
            if (justificationField) justificationField.classList.remove('show');

            if (remark) {
                remark.required = false;
                remark.classList.remove('is-invalid');
            }

            if (justification) {
                justification.required = false;
                justification.classList.remove('is-invalid');
            }

            if (photoRequired) photoRequired.style.display = 'none';

            row.classList.remove('table-danger', 'table-warning');

            if (result === 'C') {
                if (note) {
                    note.textContent = 'C — Complied.';
                    note.className = 'small text-success mt-2 floor-status-note';
                }
                return;
            }

            if (result === 'NA') {
                if (note) {
                    note.textContent = 'NA — Not Applicable.';
                    note.className = 'small text-muted mt-2 floor-status-note';
                }
                return;
            }

            if (result === 'NC') {
                if (evidence) evidence.style.display = 'block';
                if (remarkField) remarkField.classList.add('show');
                if (remark) remark.required = true;
                if (photoRequired) photoRequired.style.display = 'inline';
                row.classList.add('table-danger');

                if (note) {
                    note.textContent = 'NC — Photograph and Engineering Remark are mandatory.';
                    note.className = 'small text-danger mt-2 floor-status-note';
                }
                return;
            }

            if (result === 'CA') {
                if (evidence) evidence.style.display = 'block';
                if (justificationField) justificationField.classList.add('show');
                if (justification) justification.required = true;
                row.classList.add('table-warning');

                if (note) {
                    note.textContent = 'CA — Technical Justification is mandatory.';
                    note.className = 'small text-warning mt-2 floor-status-note';
                }
                return;
            }

            if (note) {
                note.textContent = 'Select C, NC, NA or CA.';
                note.className = 'small text-muted mt-2 floor-status-note';
            }
        }

        function updateSummary() {
            let c = 0,
                nc = 0,
                na = 0,
                ca = 0;

            form.querySelectorAll('input[name^="result["]:checked').forEach(function(input) {
                if (input.value === 'C') c++;
                if (input.value === 'NC') nc++;
                if (input.value === 'NA') na++;
                if (input.value === 'CA') ca++;
            });

            const set = (id, value) => {
                const el = document.getElementById(id);
                if (el) el.textContent = value;
            };

            set('summaryC', c);
            set('summaryNC', nc);
            set('summaryNA', na);
            set('summaryCA', ca);
        }

        function collectDraft() {
            const draft = {
                version: 'CHK-01-REV1-DOCX-131-CA',
                job_id: jobId,
                saf_id: safId,
                saved_at: new Date().toISOString(),
                results: {},
                floor_checklist: {},
                section_remarks: {},
                ground_floor_remark: '',
                final_status: '',
                inspector_signature: '',
                owner_representative: '',
                amc_representative: ''
            };

            form.querySelectorAll('input[name^="result["]:checked').forEach(function(input) {
                const ref = input.dataset.ref;
                if (!ref) return;

                draft.results[ref] = {
                    result: input.value
                };

                const remark = form.querySelector(
                    'textarea[data-remark-ref="' + esc(ref) + '"]'
                );
                const justification = form.querySelector(
                    'textarea[data-justification-ref="' + esc(ref) + '"]'
                );

                draft.results[ref].remark = remark ? remark.value : '';
                draft.results[ref].technical_justification =
                    justification ? justification.value : '';
            });

            form.querySelectorAll('.floor-check-row').forEach(function(row) {
                const floor = row.dataset.floor || '';
                const key = row.dataset.floorKey || '';
                if (!floor || !key) return;

                if (!draft.floor_checklist[floor]) {
                    draft.floor_checklist[floor] = {};
                }

                const checked = row.querySelector('input[type="radio"]:checked');
                const remark = row.querySelector('.floor-remark-input');
                const justification = row.querySelector('.floor-justification-input');

                draft.floor_checklist[floor][key] = {
                    result: checked ? checked.value : '',
                    remark: remark ? remark.value : '',
                    technical_justification: justification ? justification.value : ''
                };
            });

            form.querySelectorAll('textarea[name^="section_remarks["]').forEach(function(textarea) {
                const match = textarea.name.match(/^section_remarks\[(.+)\]$/);
                if (match) draft.section_remarks[match[1]] = textarea.value || '';
            });

            const groundRemark = form.querySelector('textarea[name="ground_floor_remark"]');
            if (groundRemark) draft.ground_floor_remark = groundRemark.value || '';

            const final = form.querySelector('input[name="final_status"]:checked');
            draft.final_status = final ? final.value : '';

            draft.inspector_signature =
                form.querySelector('input[name="inspector_signature"]')?.value || '';

            draft.owner_representative =
                form.querySelector('input[name="owner_representative"]')?.value || '';

            draft.amc_representative =
                form.querySelector('input[name="amc_representative"]')?.value || '';

            return draft;
        }

        function saveDraft() {
            if (restoring) return;

            try {
                localStorage.setItem(draftKey, JSON.stringify(collectDraft()));
                setDraftStatus('✓ Draft saved', 'saved');
            } catch (e) {
                console.error(e);
                setDraftStatus('Draft storage unavailable', 'error');
            }
        }

        function scheduleDraftSave() {
            if (restoring) return;

            setDraftStatus('Saving…', 'saving');
            clearTimeout(saveTimer);
            saveTimer = setTimeout(saveDraft, 250);
        }

        function restoreDraft() {
            let raw = null;

            try {
                raw = localStorage.getItem(draftKey);
            } catch (e) {
                return false;
            }

            if (!raw) return false;

            let draft = null;

            try {
                draft = JSON.parse(raw);
            } catch (e) {
                try {
                    localStorage.removeItem(draftKey);
                } catch (ignore) {}
                return false;
            }

            if (!draft || String(draft.job_id) !== String(jobId)) {
                return false;
            }

            restoring = true;

            try {
                if (draft.results && typeof draft.results === 'object') {
                    Object.keys(draft.results).forEach(function(ref) {
                        const item = draft.results[ref] || {};
                        const result = item.result || '';

                        if (['C', 'NC', 'NA', 'CA'].includes(result)) {
                            const radio = form.querySelector(
                                'input[name="result[' + esc(ref) + ']"][value="' + esc(result) + '"]'
                            );
                            if (radio) radio.checked = true;
                        }

                        const remark = form.querySelector(
                            'textarea[data-remark-ref="' + esc(ref) + '"]'
                        );
                        if (remark && typeof item.remark === 'string') {
                            remark.value = item.remark;
                        }

                        const justification = form.querySelector(
                            'textarea[data-justification-ref="' + esc(ref) + '"]'
                        );
                        if (justification && typeof item.technical_justification === 'string') {
                            justification.value = item.technical_justification;
                        }
                    });
                }

                if (draft.floor_checklist && typeof draft.floor_checklist === 'object') {
                    Object.keys(draft.floor_checklist).forEach(function(floor) {
                        const floorData = draft.floor_checklist[floor];
                        if (!floorData || typeof floorData !== 'object') return;

                        Object.keys(floorData).forEach(function(key) {
                            const item = floorData[key] || {};
                            const row = form.querySelector(
                                '.floor-check-row[data-floor="' + esc(floor) + '"][data-floor-key="' + esc(key) + '"]'
                            );
                            if (!row) return;

                            if (['C', 'NC', 'NA', 'CA'].includes(item.result || '')) {
                                const radio = row.querySelector(
                                    'input[type="radio"][value="' + esc(item.result) + '"]'
                                );
                                if (radio) radio.checked = true;
                            }

                            const remark = row.querySelector('.floor-remark-input');
                            const justification = row.querySelector('.floor-justification-input');

                            if (remark && typeof item.remark === 'string') remark.value = item.remark;
                            if (justification && typeof item.technical_justification === 'string') {
                                justification.value = item.technical_justification;
                            }
                        });
                    });
                }

                if (draft.section_remarks && typeof draft.section_remarks === 'object') {
                    Object.keys(draft.section_remarks).forEach(function(section) {
                        const textarea = form.querySelector(
                            'textarea[name="section_remarks[' + esc(section) + ']"]'
                        );
                        if (textarea) textarea.value = draft.section_remarks[section] || '';
                    });
                }

                const ground = form.querySelector('textarea[name="ground_floor_remark"]');
                if (ground && typeof draft.ground_floor_remark === 'string') {
                    ground.value = draft.ground_floor_remark;
                }

                if (['PASS', 'CONDITIONAL PASS', 'FAIL'].includes(draft.final_status || '')) {
                    const radio = form.querySelector(
                        'input[name="final_status"][value="' + esc(draft.final_status) + '"]'
                    );
                    if (radio) radio.checked = true;
                }

                const inspector = form.querySelector('input[name="inspector_signature"]');
                const owner = form.querySelector('input[name="owner_representative"]');
                const amc = form.querySelector('input[name="amc_representative"]');

                if (inspector) inspector.value = draft.inspector_signature || '';
                if (owner) owner.value = draft.owner_representative || '';
                if (amc) amc.value = draft.amc_representative || '';

            } finally {
                restoring = false;
            }

            form.querySelectorAll('input[name^="result["]:checked').forEach(function(input) {
                updateFixedCheckpoint(input.dataset.ref);
            });

            form.querySelectorAll('.floor-check-row').forEach(updateFloorRow);

            updateSummary();
            setDraftStatus('✓ Draft restored', 'saved');

            return true;
        }

        form.querySelectorAll('input[name^="result["]').forEach(function(input) {
            input.addEventListener('change', function() {
                updateFixedCheckpoint(input.dataset.ref);
                updateSummary();
                scheduleDraftSave();
            });
        });

        form.querySelectorAll('.floor-check-row input[type="radio"]').forEach(function(input) {
            input.addEventListener('change', function() {
                updateFloorRow(input.closest('.floor-check-row'));
                scheduleDraftSave();
            });
        });

        form.querySelectorAll('.bulk-result-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const sectionCard = button.closest('.section-card');
                const result = button.dataset.result || '';

                if (!sectionCard || !['C', 'NC', 'NA', 'CA'].includes(result)) return;

                let count = 0;

                sectionCard.querySelectorAll('tr[data-checkpoint]').forEach(function(row) {
                    const ref = row.dataset.checkpoint || '';
                    const radio = row.querySelector(
                        'input[name="result[' + esc(ref) + ']"][value="' + esc(result) + '"]'
                    );

                    if (radio) {
                        radio.checked = true;
                        updateFixedCheckpoint(ref);
                        count++;
                    }
                });

                updateSummary();
                scheduleDraftSave();

                setDraftStatus(
                    '✓ ' + result + ' applied to ' + count + ' checkpoint(s)',
                    'saved'
                );
            });
        });

        /* =====================================================
           FLOOR-WISE SELECT ALL
           Applies C / NC / NA / CA to all landing-door checkpoints
           belonging to the selected floor only.
           ===================================================== */
        form.querySelectorAll('.floor-bulk-result-btn').forEach(function(button) {
            button.addEventListener('click', function() {
                const floor = button.dataset.floor || '';
                const result = button.dataset.result || '';

                if (!floor || !['C', 'NC', 'NA', 'CA'].includes(result)) return;

                let count = 0;

                form.querySelectorAll('.floor-check-row').forEach(function(row) {
                    if ((row.dataset.floor || '') !== floor) return;

                    const radio = row.querySelector(
                        'input[type="radio"][value="' + esc(result) + '"]'
                    );

                    if (radio) {
                        radio.checked = true;
                        updateFloorRow(row);
                        count++;
                    }
                });

                scheduleDraftSave();

                setDraftStatus(
                    '✓ ' + result + ' applied to ' + count + ' checkpoint(s) on Floor ' + floor,
                    'saved'
                );
            });
        });

        form.querySelectorAll('textarea, input[type="text"]').forEach(function(input) {
            input.addEventListener('input', scheduleDraftSave);
            input.addEventListener('change', scheduleDraftSave);
        });

        form.querySelectorAll('input[name="final_status"]').forEach(function(input) {
            input.addEventListener('change', scheduleDraftSave);
        });

        form.querySelectorAll('input[type="file"]').forEach(function(input) {
            input.required = false;

            input.addEventListener('change', function() {
                input.classList.remove('is-invalid');

                if (!input.files || !input.files.length) return;

                const file = input.files[0];

                if (file.size > 3 * 1024 * 1024) {
                    input.value = '';
                    input.classList.add('is-invalid');
                    alert('Photograph size cannot exceed 3 MB.');
                    return;
                }

                setDraftStatus('✓ Draft saved • reselect photo after refresh', 'saved');
            });
        });

        function validateForm() {
            const errors = [];

            form.querySelectorAll('tr[data-checkpoint]').forEach(function(row) {
                const ref = row.dataset.checkpoint || '';
                const result = getResult(ref);

                if (!result) {
                    errors.push(ref + ': Result is required.');
                    return;
                }

                if (result === 'NC') {
                    const remark = row.querySelector('textarea[data-remark-ref]');
                    const photo = row.querySelector('input.photo-input');

                    if (!remark || remark.value.trim() === '') {
                        errors.push(ref + ': Engineering Remark is required for NC.');
                        if (remark) remark.classList.add('is-invalid');
                    }

                    if (!photo || !photo.files || photo.files.length === 0) {
                        errors.push(ref + ': Photograph is required for NC.');
                        if (photo) photo.classList.add('is-invalid');
                    }
                }

                if (result === 'CA') {
                    const justification = row.querySelector('textarea[data-justification-ref');

                    if (!justification || justification.value.trim() === '') {
                        errors.push(ref + ': Technical Justification is required for CA.');
                        if (justification) justification.classList.add('is-invalid');
                    }
                }
            });

            form.querySelectorAll('.floor-check-row').forEach(function(row) {
                const floor = row.dataset.floor || '';
                const key = row.dataset.floorKey || '';
                const checked = row.querySelector('input[type="radio"]:checked');
                const result = checked ? checked.value : '';

                if (!result) {
                    errors.push(floor + ' / ' + key + ': Status is required.');
                    return;
                }

                if (result === 'NC') {
                    const remark = row.querySelector('.floor-remark-input');
                    const photo = row.querySelector('.floor-photo-input');

                    if (!remark || remark.value.trim() === '') {
                        errors.push(floor + ' / ' + key + ': Engineering Remark is required for NC.');
                        if (remark) remark.classList.add('is-invalid');
                    }

                    if (!photo || !photo.files || photo.files.length === 0) {
                        errors.push(floor + ' / ' + key + ': Photograph is required for NC.');
                        if (photo) photo.classList.add('is-invalid');
                    }
                }

                if (result === 'CA') {
                    const justification = row.querySelector('.floor-justification-input');

                    if (!justification || justification.value.trim() === '') {
                        errors.push(floor + ' / ' + key + ': Technical Justification is required for CA.');
                        if (justification) justification.classList.add('is-invalid');
                    }
                }
            });

            const finalStatus = form.querySelector('input[name="final_status"]:checked');

            if (!finalStatus) {
                errors.push('Please select Final Inspection Status.');
            }

            return errors;
        }

        /*
         * Multi-asset submission mode.
         * Asset save = save current asset and open next incomplete asset.
         * Final submit = submit the complete SAF only after every asset
         * checklist has been completed. The server performs the final
         * all-assets validation as the authoritative check.
         */
        if (saveAssetNextButton) {
            saveAssetNextButton.addEventListener('click', function() {
                requestedSubmitMode = 'asset';

                if (saveModeInput) {
                    saveModeInput.value = 'asset';
                }
            });
        }

        if (finalSubmitSafButton) {
            finalSubmitSafButton.addEventListener('click', function(event) {
                requestedSubmitMode = 'final_submit';

                if (saveModeInput) {
                    saveModeInput.value = 'final_submit';
                }

                if (!window.confirm(
                        'All selected asset checklists must be complete before the complete SAF is sent to the Owner. Continue with Final Submit?'
                    )) {
                    event.preventDefault();
                    requestedSubmitMode = 'asset';

                    if (saveModeInput) {
                        saveModeInput.value = 'asset';
                    }

                    saveDraft();
                }
            });
        }

        form.addEventListener('submit', function(event) {
            if (saveModeInput) {
                saveModeInput.value = requestedSubmitMode;
            }

            saveDraft();

            form.querySelectorAll('.is-invalid').forEach(function(el) {
                el.classList.remove('is-invalid');
            });

            const errors = validateForm();

            if (errors.length) {
                event.preventDefault();

                saveDraft();

                let message = 'Please correct the following:\\n\\n';

                errors.slice(0, 25).forEach(function(error) {
                    message += '• ' + error + '\\n';
                });

                if (errors.length > 25) {
                    message += '\\nAnd ' + (errors.length - 25) + ' more issue(s).';
                }

                alert(message);

                const firstInvalid = form.querySelector('.is-invalid');

                if (firstInvalid) {
                    firstInvalid.scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                    firstInvalid.focus();
                }

                return false;
            }

            /*
             * The final button already asks for confirmation.
             * The normal asset-save button should save without
             * pretending that the whole SAF is being submitted.
             */
            if (requestedSubmitMode === 'final_submit') {
                setDraftStatus('✓ Complete SAF protected while submitting', 'saved');
            } else {
                setDraftStatus('✓ Asset checklist protected while saving', 'saved');
            }

            return true;
        });

        const resetButton = document.getElementById('resetChecklist');

        if (resetButton) {
            resetButton.addEventListener('click', function(event) {
                if (!window.confirm(
                        'Reset the checklist fields on this page? Unsaved data will be cleared.'
                    )) {
                    event.preventDefault();
                    return;
                }

                try {
                    localStorage.removeItem(draftKey);
                } catch (e) {}

                window.setTimeout(function() {
                    form.querySelectorAll('.dynamic-required').forEach(function(el) {
                        el.classList.remove('show');
                    });

                    form.querySelectorAll('.result-evidence').forEach(function(el) {
                        el.classList.remove('show');
                    });

                    form.querySelectorAll('.floor-result-evidence').forEach(function(el) {
                        el.style.display = 'none';
                    });

                    updateSummary();
                    setDraftStatus('Draft cleared', 'saving');
                }, 50);
            });
        }

        window.addEventListener('beforeunload', function() {
            if (!restoring) saveDraft();
        });

        restoreDraft();

        form.querySelectorAll('input[name^="result["]:checked').forEach(function(input) {
            updateFixedCheckpoint(input.dataset.ref);
        });

        form.querySelectorAll('.floor-check-row').forEach(updateFloorRow);

        updateSummary();
    })();
</script>

<?php include('../includes/footer.php'); ?>