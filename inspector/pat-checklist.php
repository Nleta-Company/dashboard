<?php
/* =========================================================
   NLETA INSPECTOR — SAF / PAT CHECKLIST
   CHK-01 — PAT MASTER CHECKLIST
   Revision: Rev.1

   SOURCE OF TRUTH:
   PAT Checklist_01_Rev.1.docx

   FIXED MASTER CHECKPOINTS:
   PAT-001 -> PAT-129 (129 active fixed checkpoints after requested removals/reclassification)

   SECTION D:
   - D1 Common: PAT-021 -> PAT-024
   - D2 Dynamic Per Floor Landing Checklist
   - D3 Ground Floor Only: PAT-025 -> PAT-026

   RESULT CODES:
   C  = Complied
   NC = Non-Complied
   NA = Not Applicable
   CA = Conditional Acceptance

   MANDATORY RULES:
   NC -> Photograph OR Video REQUIRED
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

function h($value): string {
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
    die(
        '<div class="nleta-db-error">'
        . '<h3>CHK-01 Database Error</h3>'
        . '<strong>Prepare failed:</strong> ' . h($conn->error)
        . '</div>'
    );
}

$stmt->bind_param('ii', $jobId, $inspectorId);

if (!$stmt->execute()) {
    die(
        '<div class="nleta-db-error">'
        . '<h3>CHK-01 Database Error</h3>'
        . '<strong>Execute failed:</strong> ' . h($stmt->error)
        . '</div>'
    );
}

$job = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$job) {
    header('Location: inspections.php');
    exit;
}

/* =========================================================
   LOAD SERVER-SAVED EVIDENCE
   ---------------------------------------------------------
   Evidence is stored immediately in saf_checklist_evidence
   when the inspector selects a Photo/Video for an NC or CA.
   This makes evidence survive refresh/back/re-open.
========================================================= */
$savedEvidenceForUi = [];

if ($jobId > 0) {
    $tableCheck = $conn->query("SHOW TABLES LIKE 'saf_checklist_evidence'");

    if ($tableCheck && $tableCheck->num_rows > 0) {
        $evStmt = $conn->prepare("
            SELECT ref_code, result, photo_path, video_path
            FROM saf_checklist_evidence
            WHERE job_id = ?
        ");

        if ($evStmt) {
            $evStmt->bind_param('i', $jobId);

            if ($evStmt->execute()) {
                $evRes = $evStmt->get_result();

                while ($evRow = $evRes->fetch_assoc()) {
                    $ref = trim((string)($evRow['ref_code'] ?? ''));
                    if ($ref === '') {
                        continue;
                    }

                    $savedEvidenceForUi[$ref] = [
                        'result' => strtoupper(trim((string)($evRow['result'] ?? ''))),
                        'photo'  => trim((string)($evRow['photo_path'] ?? '')),
                        'video'  => trim((string)($evRow['video_path'] ?? '')),
                    ];
                }
            }

            $evStmt->close();
        }
    }
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
   FINAL SUBMISSION LOCK
   ---------------------------------------------------------
   "completed" is not a permanent lock. The checklist remains
   editable until the complete SAF Final Submit is completed.

   Once inspector_assignments.status becomes "submitted",
   this original Lift PAT checklist is permanently view-only.
========================================================= */
$assignmentStatus = strtolower(
    trim((string)($job['assignment_status'] ?? ''))
);

$isFinalSubmitted = in_array(
    $assignmentStatus,
    ['submitted', 'final_submitted'],
    true
);

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
            ['PAT-001', 'Shaft pit constructed as approved drawing', 'P3'],
            ['PAT-002', 'Headroom available as per GAD', 'P3'],
            ['PAT-003', 'Shaft ventilation provided', 'P3'],
            ['PAT-004', 'Shaft lighting installed and operational', 'P2'],
        ],
    ],
    'B' => [
        'title' => 'SECTION B — SHAFT EQUIPMENT',
        'items' => [ 
            ['PAT-005', 'Shaft white wash completed', 'P4'],
            ['PAT-006', 'Guide rail fish plate bolts tightened', 'P3'],
            ['PAT-007', 'Electrical wiring properly secured', 'P2'],
            ['PAT-008', 'Wiring clear from moving parts', 'P2'],
            ['PAT-009', 'Shaft protected against rainwater ingress', 'P3'],
            ['PAT-010', 'Main & Counterweight guide rails match GAD', 'P4'],
        ],
    ],
    'C' => [
        'title' => 'SECTION C — PIT',
        'items' => [ 
            ['PAT-011', 'OSG distance from pit floor verified', 'P4'],
            ['PAT-012', 'Pit electrical wiring satisfactory', 'P3'],
            ['PAT-013', 'Buffers installed as per GAD', 'P1'],
            ['PAT-014', 'Car run-by within permissible limit', 'P4'],
            ['PAT-015', 'Counterweight run-by verified', 'P4'],
            ['PAT-016', 'Pit ladder installed correctly', 'P3'],
            ['PAT-017', 'Oil collectors leak proof', 'P4'],
            ['PAT-018', 'Pit cleanliness / painting / marking', 'P3'],
            ['PAT-019', 'Pit lighting operational', 'P2'],
            ['PAT-020', 'Water drainage provision available', 'P4'],
        ],
    ],
    'D' => [
        'title' => 'SECTION D — LANDING DOOR',
        'items' => [
            ['PAT-021', 'Landing doors aligned in vertical line', 'P2'],
            ['PAT-022', 'Landing doors connected in safety circuit', 'P1'],
            ['PAT-023', 'Toe guard installed below landing door', 'P3'],
            ['PAT-024', 'Landing door electrical wiring satisfactory', 'P2'],
        ],
    ],
    'E' => [
        'title' => 'SECTION E — CAR FRAME',
        'items' => [ 
            ['PAT-027', 'Isolation pads provided', 'P2'],
            ['PAT-028', 'OEM nameplate with design life', 'P3'],
            ['PAT-029', 'Diverter pulley aligned & guarded', 'P2'],
            ['PAT-030', 'Safety gear with valid certificate', 'P1 + CA'],
            ['PAT-031', 'Safety gear linkage connected', 'P1'],
            ['PAT-032', 'Earthing provided', 'P2'],
            ['PAT-033', 'Overload cell operational', 'P1'],
            ['PAT-034', 'Electrical safety switch functional', 'P1'],
        ],
    ],
    'F' => [
        'title' => 'SECTION F — DRIVE SYSTEM',
        'items' => [ 
            ['PAT-035', 'VVVF drive certified', 'P2 + CA'],
            ['PAT-036', 'Emergency brake lever available', 'P1'],
            ['PAT-037', 'Fasteners secured', 'P4'],
            ['PAT-038', 'Electrical wiring satisfactory', 'P4'],
            ['PAT-039', 'Earthing continuity verified', 'P1'],
            ['PAT-042', 'Correct rating verified', 'P2'],
            ['PAT-043', 'Tripping function tested', 'P2'],
        ],
    ],
    'G' => [
        'title' => 'SECTION G — CONTROLLER',
        'items' => [ 
            ['PAT-040', 'ARD Installation', 'P1'],
            ['PAT-044', 'Controller securely installed', 'P4'],
            ['PAT-045', 'Internal wiring satisfactory', 'P2'],
            ['PAT-046', 'Earthing connected', 'P1'],
            ['PAT-047', 'Controller door earthed', 'P1'],
            ['PAT-048', 'Maintenance switch tested', 'P1'],
            ['PAT-049', 'Stop switch tested', 'P1'],
            ['PAT-050', 'Lockable controller with danger label', 'P2'],
            ['PAT-051', 'Wiring diagram available', 'P3'],
            ['PAT-052', 'Diagnostic keypad / HHT available', 'P3'],
            ['PAT-053', 'MCB/ELCB provided', 'P4'],
        ],
    ],
    'H' => [
        'title' => 'SECTION H — OVERSPEED GOVERNOR',
        'items' => [ 
            ['PAT-054', 'OSG pulley alignment', 'P1'],
            ['PAT-055', 'Nameplate readable', 'P3'],
            ['PAT-056', 'Test certificate available', 'P2'],
            ['PAT-057', 'Tripping speed verified', 'P1'],
            ['PAT-058', 'Tension weight & safety switch', 'P1'],
            ['PAT-059', 'OSG rope clean without lubrication', 'P2'],
            ['PAT-060', 'Pit pulley clearance adequate', 'P3'],
        ],
    ],
    'I' => [
        'title' => 'SECTION I — CABIN',
        'items' => [ 
            ['PAT-061', 'Guide shoes satisfactory', 'P2'],
            ['PAT-062', 'All fasteners secured', 'P4'],
            ['PAT-063', 'Oil cups installed', 'P3'],
            ['PAT-064', 'Emergency hooter operational', 'P1'],
            ['PAT-065', 'Handrail securely fixed', 'P3'],
            ['PAT-066', 'Cabin lighting operational', 'P3'],
            ['PAT-067', 'Car top wiring protected', 'P2'],
            ['PAT-068', 'COP stop button tested', 'P1'],
            ['PAT-069', 'Intercom operational', 'P1'],
            ['PAT-070', 'Cabin wall & ceiling satisfactory', 'P3'],
            ['PAT-071', 'COP buttons operational', 'P3'],
            ['PAT-072', 'Position indicator functional', 'P3'],
            ['PAT-073', 'Capacity plate fixed', 'P3'],
            ['PAT-074', 'CCTV installed', 'P2'],
            ['PAT-075', 'Car top junction box operational', 'P2'],
            ['PAT-076', 'Maintenance switch operational', 'P1'],
            ['PAT-077', 'Inspection UP/DOWN operation verified', 'P1'],
            ['PAT-078', 'Cabin fans operational', 'P3'],
        ],
    ],
    'J' => [
        'title' => 'SECTION J — CAR DOOR',
        'items' => [ 
            ['PAT-079', 'Car toe guard installed', 'P3'],
            ['PAT-080', 'Door opening / closing adjusted', 'P2'],
            ['PAT-081', 'Fasteners secured', 'P4'],
            ['PAT-082', '3D sensor / photocell working', 'P1'],
            ['PAT-083', 'Sill clearance uniform', 'P2'],
            ['PAT-084', 'Entrance dimensions verified', 'P4'],
            ['PAT-085', 'Car door gap from jam within limits', 'P2'],
            ['PAT-086', 'Lobby free from stumbling obstacles', 'P3'],
            ['PAT-087', 'Intercom connected with security room', 'P2'],
        ],
    ],
    'K' => [
        'title' => 'SECTION K — COUNTERWEIGHT',
        'items' => [ 
            ['PAT-088', 'Counterweight balanced at 50% load', 'P1'],
            ['PAT-089', 'Guide shoes satisfactory', 'P2'],
            ['PAT-090', 'Oil cups installed', 'P3'],
            ['PAT-091', 'Counterweight pulley aligned', 'P1'],
            ['PAT-092', 'Weights locked with tie rod', 'P1'],
            ['PAT-093', 'Counterweight safety gear (Hanging Pit)', 'P1'],
            ['PAT-094', 'Counterweight guard provided', 'P2'],
            ['PAT-095', 'Guard height & clearance satisfactory', 'P2'],
        ],
    ],
    'L' => [
        'title' => 'SECTION L — ROPE',
        'items' => [ 
            ['PAT-096', 'Rope fastening verified', 'P1'],
            ['PAT-097', 'Uniform rope tension', 'P2'],
            ['PAT-098', 'Rope ends secured with bulldog clips', 'P1'],
            ['PAT-099', 'No rope twist', 'P1'],
            ['PAT-100', 'Rope clean without grease', 'P3'],
            ['PAT-101', 'Rope test certificates available', 'P2 + CA'],
            ['PAT-102', 'Floor level markings completed', 'P1'],
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
            ['PAT-041', 'RCCB / ELCB installed', 'P2'],
            ['PAT-107', 'Machine serial matches nameplate', 'P2'],
            ['PAT-108', 'Load capacity matches GAD', 'P1 + CA'],
            ['PAT-109', 'Isolation pads provided', 'P2'],
            ['PAT-110', 'Minimum two earthing strips', 'P1'],
            ['PAT-111', 'No abnormal sound', 'P2'],
            ['PAT-112', 'Vibration within range', 'P2'],
            ['PAT-113', 'Shock treatment chart displayed', 'P3'],
            ['PAT-114', 'Rescue diagram displayed', 'P2'],
            ['PAT-115', 'Caution signage provided', 'P3'],
            ['PAT-116', 'Smoke window provided (MRL only)', 'P2'],
            ['PAT-117', 'Fire extinguisher in Machine Room (MR Lift)', 'P1'],
            ['PAT-118', 'Fire extinguisher beside Top Controller (MRL)', 'P1'],
            ['PAT-119', 'Machine room accessibility safe', 'P2'],
            ['PAT-120', 'Exhaust fan operational', 'P3'],
            ['PAT-121', 'Machine room lighting adequate', 'P2'],
            ['PAT-122', 'Emergency stop provided', 'P1'],
            ['PAT-123', 'Main cable type & size verified', 'P2'],
        ],
    ],
    'O' => [
        'title' => 'SECTION O — DOCUMENTATION',
        'items' => [ 
            ['PAT-124', 'Log book box installed', 'P3'],
            ['PAT-125', 'Designated maintenance log available', 'P2'],
            ['PAT-126', 'OEM/AMC safety certificate displayed', 'P2'],
            ['PAT-127', 'Valid CAMC / AMC agreement available', 'P1 + CA'],
            ['PAT-128', '“NOT IN USE” display available', 'P3'],
            ['PAT-129', 'Valid insurance copy available', 'P2'],
        ],
    ],
];

$groundFloorItems = [
    ['PAT-025', 'Fireman glass & brake hammer available', 'P1'],
    ['PAT-026', 'Fireman switch operational', 'P1'],
];

/* =========================================================
   D2 DYNAMIC PER-FLOOR LANDING CHECKLIST
   These are execution rows and do NOT create PAT-132+ IDs.
========================================================= */

$floorCheckpointDefinitions = [
    'landing_door_alignment' => [
        'description' => 'Landing door alignment',
        'priority' => 'P2'
    ],
    'landing_door_manual_lock' => [
        'description' => 'Landing door manual lock functional',
        'priority' => 'P1'
    ],
    'landing_push_button' => [
        'description' => 'Landing push button operational',
        'priority' => 'P3'
    ],
    'braille_button' => [
        'description' => 'Braille button provided',
        'priority' => 'P4'
    ],
    'hall_display' => [
        'description' => 'Hall display operational',
        'priority' => 'P2'
    ],
    'entrance_dimensions' => [
        'description' => 'Entrance width & height verified',
        'priority' => 'P4'
    ],
    'door_jamb_clearance' => [
        'description' => 'Door to jamb clearance within limit',
        'priority' => 'P1'
    ],
    'sill_gap_verified' => [
        'description' => 'Sill gap verified',
        'priority' => 'P3'
    ]
];

function normalizeFloorLabel(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    return preg_replace('/\s+/', ' ', $value);
}

function parseServedFloors(array $job): array {
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

if ($totalFixedCheckpoints !== 129) {
    die(
        'CHK-01 master configuration error. Expected 129 active fixed checkpoints, found '
        . $totalFixedCheckpoints . '.'
    );
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

$existingRopePhysicalCondition = (string)($existingChecklist['rope_physical_condition'] ?? '');
$existingRopeReplacementDate = (string)($existingChecklist['rope_replacement_date'] ?? '');
$existingRopeAcceptanceResult = strtoupper(trim((string)($existingChecklist['rope_acceptance_result'] ?? '')));
$existingRopeTechnicalJustification = (string)($existingChecklist['rope_technical_justification'] ?? '');

$currentIST = date('d/m/Y H:i:s');

/* =========================================================
   HELPERS FOR RESULT DISPLAY
========================================================= */

function savedResult(array $results, string $key): string {
    $item = $results[$key] ?? [];

    if (is_string($item)) {
        $value = strtoupper(trim($item));
    } elseif (is_array($item)) {
        $value = strtoupper(trim((string)($item['result'] ?? '')));
    } else {
        $value = '';
    }

    return in_array($value, ['C','NC','NA','CA'], true)
        ? $value
        : '';
}

function savedRemark(array $results, string $key): string {
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['remark'] ?? '')
        : '';
}

function savedJustification(array $results, string $key): string {
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['technical_justification'] ?? '')
        : '';
}

function savedPhoto(array $results, string $key): string {
    $item = $results[$key] ?? [];

    return is_array($item)
        ? (string)($item['photo'] ?? '')
        : '';
}

include('../includes/header.php');
include('../includes/sidebar-inspector.php');
?>

<style>
.nleta-db-error{margin:30px;padding:20px;border:1px solid #dc3545;border-radius:10px;background:#fff5f5;font-family:Arial,sans-serif;color:#212529}.nleta-db-error h3{color:#b02a37;margin-top:0}
.nleta-checklist-page{min-height:calc(100vh - 70px);padding:24px;background:#f4f6f9}
.nleta-card{background:#fff;border:1px solid #e1e5e9;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.06);margin-bottom:18px;overflow:hidden}
.nleta-card-header{padding:20px 22px;border-bottom:1px solid #e5e7eb}
.page-title{margin:0;font-size:26px;font-weight:800;color:#172033}
.page-subtitle{margin-top:5px;color:#6c757d}
.controlled-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 11px;border-radius:20px;background:#e7f1ff;color:#0d6efd;font-size:12px;font-weight:800}
.job-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;padding:18px}
.job-box{background:#f8f9fa;border:1px solid #e3e7eb;border-radius:10px;padding:12px}
.job-label{color:#6c757d;font-size:11px;text-transform:uppercase;font-weight:700}
.job-value{color:#172033;font-weight:700;margin-top:4px;word-break:break-word}
.legend-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;padding:18px}
.legend-item{border:1px solid #e1e5e9;border-radius:10px;padding:13px;background:#fff}
.legend-code{font-size:18px;font-weight:900}.legend-description{color:#6c757d;font-size:13px;margin-top:3px}
.legend-c{color:#198754}.legend-nc{color:#dc3545}.legend-na{color:#6c757d}.legend-ca{color:#b27a00}
.rule-box{margin:0 18px 18px;border:1px solid #ffe08a;background:#fff9df;border-radius:10px;padding:13px 15px;color:#624d03;font-size:13px}
.section-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:14px 18px;background:#0d6efd;color:#fff}
.section-heading-title{font-size:17px;font-weight:800}.section-count{background:rgba(255,255,255,.18);border-radius:20px;padding:5px 10px;font-size:11px;font-weight:800}
.section-bulk-result{padding:12px 18px;background:#f8fbff;border-bottom:1px solid #dbe8f7}
.section-bulk-label{font-size:12px;font-weight:800;color:#26364d;margin-bottom:8px}
.section-bulk-buttons{display:flex;flex-wrap:wrap;gap:7px}
.bulk-result-btn{border:1px solid #ced4da;border-radius:7px;background:#fff;padding:7px 11px;font-size:12px;font-weight:800;cursor:pointer;transition:.15s}
.bulk-result-btn:hover{transform:translateY(-1px);box-shadow:0 2px 7px rgba(0,0,0,.08)}
.bulk-c{color:#198754;border-color:#b7dfc8}.bulk-c:hover{background:#eaf8ef}
.bulk-nc{color:#dc3545;border-color:#f1b7bd}.bulk-nc:hover{background:#fff0f1}
.bulk-na{color:#6c757d;border-color:#cfd3d7}.bulk-na:hover{background:#f1f3f5}
.bulk-ca{color:#b27a00;border-color:#ead28a}.bulk-ca:hover{background:#fff9df}
.table-wrap{overflow-x:auto}.checklist-table{width:100%;border-collapse:collapse;min-width:1080px}
.checklist-table th{background:#f7f8fa;border-bottom:2px solid #dee2e6;color:#26364d;padding:11px 10px;font-size:12px;font-weight:800;text-transform:uppercase;white-space:nowrap}
.checklist-table td{padding:10px;border-bottom:1px solid #edf0f3;vertical-align:top;font-size:13px}
.checkpoint-text{font-weight:650;color:#202938;line-height:1.45}.ref-code{font-weight:900;color:#172033;white-space:nowrap}
.priority{display:inline-block;min-width:30px;text-align:center;padding:5px 7px;border-radius:6px;font-size:11px;font-weight:900}
.priority-p1{background:#fde2e2;color:#a61b1b}.priority-p2{background:#fff0c2;color:#795b00}.priority-p3{background:#e5f0ff;color:#1557a6}.priority-p4{background:#e7e7e7;color:#555}
.result-options{display:flex;flex-wrap:wrap;gap:6px;min-width:255px}.result-option{display:inline-flex;align-items:center;gap:4px;padding:6px 8px;border:1px solid #d8dde3;border-radius:7px;background:#fff;cursor:pointer;font-size:12px;font-weight:700}.result-option input{margin:0}
.evidence-area{min-width:270px;max-width:350px}.result-evidence{display:none}.result-evidence.show{display:block}
.dynamic-required{display:none}.dynamic-required.show{display:block}
.field-group{margin-top:8px}.field-group:first-child{margin-top:0}.field-label{display:block;font-size:11px;font-weight:800;margin-bottom:4px}.required{color:#dc3545}
.file-input{width:100%;font-size:12px}.file-help{display:block;margin-top:4px;font-size:10px;color:#6c757d}
.saved-evidence-box{display:none;margin-top:9px;padding:9px 10px;border:1px solid #b7dfc8;border-radius:8px;background:#f3fff7;color:#146c43;font-size:11px;line-height:1.45}
.saved-evidence-box.show{display:block}
.saved-evidence-title{font-weight:900;margin-bottom:4px}
.saved-evidence-item{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:3px 0}
.saved-evidence-item a{font-weight:800;text-decoration:none}
.saved-evidence-meta{min-width:0;overflow-wrap:anywhere;word-break:break-word}
.saved-evidence-replace{color:#0d6efd;font-size:10px;font-weight:800}
.remark-input,.justification-input{width:100%;min-height:58px;resize:vertical;border:1px solid #ced4da;border-radius:7px;padding:8px;font-size:12px}
.summary-grid{display:grid;gap:10px;padding:18px 18px 0}
.summary-grid-totals{grid-template-columns:repeat(3,minmax(0,1fr))}
.summary-grid-results{grid-template-columns:repeat(4,minmax(0,1fr));padding-top:0;padding-bottom:18px}
.summary-box{border:1px solid #e1e5e9;border-radius:10px;padding:14px;background:#fff;min-width:0}.summary-label{color:#6c757d;font-size:12px;font-weight:700}.summary-value{margin-top:4px;font-size:24px;font-weight:900}
.summary-total{color:#0d6efd}.summary-c{color:#198754}.summary-nc{color:#dc3545}.summary-na{color:#6c757d}.summary-ca{color:#b27a00}
.final-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;padding:18px}
.final-option{border:1px solid #ced4da;border-radius:10px;padding:14px;cursor:pointer;font-weight:800;background:#fff}.final-option input{margin-right:7px}
.final-fields{padding:0 18px 18px;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
.final-field label{display:block;font-size:12px;font-weight:800;margin-bottom:5px}.final-field input{width:100%;border:1px solid #ced4da;border-radius:7px;padding:9px;font-size:13px}
.draft-status{display:inline-flex;align-items:center;gap:6px;margin-left:12px;font-size:12px;font-weight:700;color:#198754}
.draft-status.saving{color:#b27a00}.draft-status.saved{color:#198754}.draft-status.error{color:#dc3545}
.action-bar{position:sticky;bottom:0;z-index:20;background:rgba(255,255,255,.97);border-top:1px solid #dee2e6;padding:14px 18px;box-shadow:0 -3px 12px rgba(0,0,0,.05)}
.action-content{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.is-invalid{border-color:#dc3545!important}
.floor-note{margin:0 18px 15px;border:1px solid #cfe2ff;background:#f4f8ff;border-radius:10px;padding:12px 14px;color:#244b75;font-size:13px}
.floor-title{font-weight:800;color:#17395c}.floor-badge{display:inline-block;padding:5px 9px;border-radius:15px;background:#e7f1ff;color:#0d6efd;font-size:11px;font-weight:800}
.ground-row{background:#fffdf2}
.floor-bulk-area{margin:0 18px 15px;border:1px solid #e1e5e9;border-radius:10px;background:#fff;padding:14px}.floor-bulk-heading{font-size:13px;font-weight:900;color:#26364d;margin-bottom:4px}.floor-bulk-help{font-size:10px;color:#6c757d;line-height:1.45;margin-bottom:12px}.floor-bulk-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:9px 0;border-top:1px solid #edf0f3}.floor-bulk-row:first-of-type{border-top:0}.floor-bulk-name{min-width:95px}.floor-bulk-buttons{display:flex;flex-wrap:wrap;gap:7px}.floor-bulk-result-btn{min-width:135px}
.asset-selector-card{margin:0 0 18px;background:#fff;border:1px solid #dfe5ec;border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);overflow:hidden}
.asset-selector-header{padding:16px 20px;background:#f7faff;border-bottom:1px solid #dbe5f0}
.asset-selector-title{font-size:18px;font-weight:900;color:#172033}
.asset-selector-subtitle{font-size:12px;color:#6c757d;margin-top:4px}
.asset-progress{display:inline-flex;align-items:center;gap:7px;padding:6px 10px;border-radius:18px;background:#e7f1ff;color:#0d6efd;font-size:12px;font-weight:900}
.asset-progress.complete{background:#eaf8ef;color:#198754}
.asset-selector-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;padding:16px}
.asset-selector-item{border:2px solid #e2e7ed;border-radius:12px;padding:14px;background:#fff;transition:.15s}
.asset-selector-item.current{border-color:#0d6efd;background:#f4f8ff;box-shadow:0 3px 12px rgba(13,110,253,.10)}
.asset-selector-item.completed{border-color:#b7dfc8;background:#f7fff9}
.asset-selector-top{display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.asset-selector-id{font-size:16px;font-weight:900;color:#0d6efd;word-break:break-word}
.asset-selector-name{margin-top:5px;font-weight:700;color:#26364d}
.asset-selector-location{margin-top:3px;font-size:12px;color:#6c757d}
.asset-selector-status{font-size:10px;font-weight:900;padding:5px 8px;border-radius:15px;white-space:nowrap}
.asset-status-pending{background:#fff3cd;color:#856404}
.asset-status-complete{background:#d1e7dd;color:#0f5132}
.asset-selector-action{margin-top:12px;width:100%}
.final-submit-note{margin:0 18px 18px;padding:13px 15px;border-radius:10px;border:1px solid #cfe2ff;background:#f4f8ff;color:#244b75;font-size:13px}
.final-submit-note.ready{border-color:#b7dfc8;background:#f3fff7;color:#146c43}
.final-submit-note strong{font-weight:900}
@media(max-width:1000px){.asset-selector-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:650px){.asset-selector-grid{grid-template-columns:1fr}}

@media(max-width:1200px){.summary-grid-results{grid-template-columns:repeat(4,minmax(0,1fr))}}
@media(max-width:1000px){.job-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.summary-grid-totals{grid-template-columns:repeat(3,minmax(0,1fr))}.summary-grid-results{grid-template-columns:repeat(4,minmax(0,1fr))}.final-fields{grid-template-columns:1fr}}
@media(max-width:650px){.floor-bulk-name{min-width:100%}.floor-bulk-result-btn{flex:1 1 140px}.nleta-checklist-page{padding:10px}.job-grid,.legend-grid{grid-template-columns:1fr}.summary-grid-totals{grid-template-columns:1fr}.summary-grid-results{grid-template-columns:repeat(2,minmax(0,1fr))}.final-status-grid{grid-template-columns:1fr}.page-title{font-size:21px}}

/* =========================================================
   FINAL RESPONSIVE OVERRIDES
   ---------------------------------------------------------
   Desktop keeps the existing layout.
   Tablet/mobile:
   - cards become compact 2-column where practical
   - tables stay usable with horizontal scrolling
   - result/evidence controls wrap cleanly
   - no page-level horizontal overflow
========================================================= */

.optional-label{
    color:#6c757d;
    font-size:10px;
    font-weight:700;
}

.optional-video-field,
.optional-floor-video-field{
    display:block;
}

.video-input,
.floor-video-input{
    width:100%;
    max-width:100%;
}

.file-help{
    overflow-wrap:anywhere;
    word-break:break-word;
}

.nleta-checklist-page,
.nleta-card,
.nleta-card-header,
.job-grid,
.legend-grid,
.summary-grid,
.final-fields,
.action-bar,
.action-content{
    min-width:0;
    max-width:100%;
}

.table-wrap{
    width:100%;
    max-width:100%;
    overflow-x:auto !important;
    overflow-y:visible;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
}

.checklist-table{
    min-width:1080px;
}

@media (max-width: 1000px){
    .nleta-checklist-page{
        padding:14px;
    }

    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .legend-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .summary-grid-totals{
        grid-template-columns:repeat(3,minmax(0,1fr));
    }

    .summary-grid-results{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .final-fields{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .asset-selector-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .nleta-card-header{
        padding:16px;
    }

    .section-heading{
        flex-wrap:wrap;
    }

    .section-bulk-buttons,
    .floor-bulk-buttons{
        width:100%;
    }

    .bulk-result-btn,
    .floor-bulk-result-btn{
        white-space:normal;
    }
}

@media (max-width: 650px){
    .nleta-checklist-page{
        padding:8px;
    }

    .nleta-card{
        border-radius:10px;
        margin-bottom:12px;
    }

    .nleta-card-header{
        padding:13px;
    }

    .page-title{
        font-size:20px;
        line-height:1.2;
    }

    .page-subtitle{
        font-size:12px;
    }

    .job-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
        padding:10px;
    }

    .job-box{
        padding:9px;
    }

    .job-label{
        font-size:9px;
    }

    .job-value{
        font-size:12px;
    }

    .legend-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
        padding:10px;
    }

    .legend-item{
        padding:10px;
    }

    .legend-code{
        font-size:16px;
    }

    .legend-description{
        font-size:11px;
    }

    .rule-box{
        margin:0 10px 10px;
        font-size:11px;
        line-height:1.5;
    }

    .rule-box .ms-2{
        margin-left:0 !important;
        display:block;
    }

    .section-heading{
        padding:11px 12px;
    }

    .section-heading-title{
        font-size:14px;
        line-height:1.25;
    }

    .section-count{
        font-size:9px;
    }

    .section-bulk-result{
        padding:10px 12px;
    }

    .section-bulk-buttons{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:6px;
    }

    .bulk-result-btn{
        width:100%;
        min-width:0;
        padding:7px 6px;
        font-size:10px;
    }

    .table-wrap{
        margin:0;
        padding:0 0 4px;
        border-top:0;
    }

    .checklist-table{
        min-width:1020px;
    }

    .checklist-table th,
    .checklist-table td{
        padding:8px;
        font-size:11px;
    }

    .result-options{
        min-width:230px;
        gap:5px;
    }

    .result-option{
        padding:5px 7px;
        font-size:11px;
    }

    .evidence-area{
        min-width:245px;
        max-width:310px;
    }

    .field-label{
        font-size:10px;
    }

    .file-input{
        font-size:11px;
    }

    .file-help{
        font-size:9px;
    }

    .summary-grid{
        padding:10px;
    }

    .summary-grid-totals{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .summary-grid-results{
        grid-template-columns:repeat(2,minmax(0,1fr));
        padding-top:0;
        padding-bottom:10px;
    }

    .summary-box{
        padding:10px;
    }

    .summary-value{
        font-size:20px;
    }

    .final-status-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        padding:10px;
    }

    .final-option{
        padding:10px;
        font-size:11px;
    }

    .final-fields{
        grid-template-columns:1fr;
        padding:0 10px 10px;
    }

    .action-bar{
        padding:10px;
    }

    .action-content{
        align-items:stretch;
    }

    .action-content > div{
        width:100%;
    }

    .action-content .btn{
        margin:3px 0 !important;
        width:100%;
    }

    .draft-status{
        margin-left:0;
        display:block;
        margin-top:4px;
    }

    .floor-note,
    .floor-bulk-area,
    .final-submit-note{
        margin-left:10px;
        margin-right:10px;
    }

    .floor-bulk-row{
        align-items:stretch;
    }

    .floor-bulk-name{
        min-width:100%;
    }

    .floor-bulk-buttons{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        width:100%;
        gap:6px;
    }

    .floor-bulk-result-btn{
        min-width:0;
        width:100%;
        font-size:10px;
        padding:7px 5px;
    }

    .asset-selector-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
        padding:10px;
    }

    .asset-selector-item{
        padding:10px;
    }

    .asset-selector-id{
        font-size:13px;
    }

    .asset-selector-name{
        font-size:11px;
    }

    .asset-selector-location{
        font-size:10px;
    }
}

@media (max-width: 380px){
    .job-grid{
        gap:6px;
        padding:8px;
    }

    .job-box{
        padding:7px;
    }

    .job-value{
        font-size:11px;
    }

    .section-bulk-buttons,
    .floor-bulk-buttons{
        grid-template-columns:1fr 1fr;
    }

    .bulk-result-btn,
    .floor-bulk-result-btn{
        font-size:9px;
    }

    .summary-value{
        font-size:18px;
    }

    .asset-selector-grid{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
}


/* =========================================================
   FINAL SUBMITTED — VIEW ONLY
========================================================= */
.pat-final-lock-banner{
    margin:0 0 18px;
    padding:16px 18px;
    border:2px solid #b6d4fe;
    border-radius:14px;
    background:#eef6ff;
    display:flex;
    align-items:flex-start;
    gap:14px;
    color:#084298;
    box-shadow:0 3px 12px rgba(0,0,0,.05);
}
.pat-final-lock-icon{
    width:42px;
    height:42px;
    min-width:42px;
    border-radius:50%;
    background:#0d6efd;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:18px;
}
.pat-final-lock-title{
    font-weight:800;
    font-size:16px;
    line-height:1.3;
}
.pat-final-lock-text{
    margin-top:4px;
    font-size:13px;
    line-height:1.5;
    color:#315b8f;
}
.pat-checklist-readonly{
    opacity:.98;
}
@media(max-width:600px){
    .pat-final-lock-banner{padding:13px;gap:10px}
    .pat-final-lock-icon{width:36px;height:36px;min-width:36px}
    .pat-final-lock-title{font-size:14px}
    .pat-final-lock-text{font-size:12px}
}


.rope-assessment-card{border:1px solid #d9e2ec;border-left:4px solid #0d6efd;border-radius:12px;background:#fff;margin:14px 0 18px;padding:18px}.rope-assessment-title{font-size:16px;font-weight:700;margin-bottom:6px}.rope-assessment-note{font-size:13px;color:#667085;margin-bottom:14px}.rope-rule-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:12px 0 16px}.rope-rule-item{border:1px solid #e5e7eb;border-radius:8px;padding:9px 10px;font-size:12px;line-height:1.45}.rope-assessment-status{margin-top:12px;padding:10px 12px;border-radius:8px;font-weight:600;background:#f8fafc}@media(max-width:900px){.rope-rule-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:576px){.rope-rule-grid{grid-template-columns:1fr}}
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
        <div class="job-box"><div class="job-label">PSGA / Contract No.</div><div class="job-value"><?= h($job['psga_no'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">SAF No.</div><div class="job-value"><?= h($job['saf_no'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Asset / Lift</div><div class="job-value"><?= h($job['display_asset_id'] ?: ($job['asset_name'] ?: '—')) ?></div></div>
        <div class="job-box"><div class="job-label">Owner</div><div class="job-value"><?= h($job['owner_name'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Inspector</div><div class="job-value"><?= h($_SESSION['name'] ?? $_SESSION['full_name'] ?? 'Assigned Inspector') ?></div></div>
        <div class="job-box"><div class="job-label">Location</div><div class="job-value"><?= h($job['location'] ?? '—') ?></div></div>
        <div class="job-box"><div class="job-label">Service</div><div class="job-value"><?= h($job['service_name'] ?? 'PAT') ?></div></div>
        <div class="job-box"><div class="job-label">Equipment Type</div><div class="job-value"><?= h($job['asset_type'] ?: ($job['category'] ?: 'Lift')) ?></div></div>
        <div class="job-box"><div class="job-label">Inspection Date</div><div class="job-value">
            <?php
            $inspectionDate = (string)($job['proposed_inspection_date'] ?? '');
            if ($inspectionDate !== '') {
                $ts = strtotime($inspectionDate);
                echo $ts !== false ? h(date('d/m/Y', $ts)) : h($inspectionDate);
            } else {
                echo '—';
            }
            ?>
        </div></div>
        <div class="job-box"><div class="job-label">Checklist</div><div class="job-value">CHK-01 Rev.1</div></div>
        <div class="job-box"><div class="job-label">Total Execution Checkpoints</div><div class="job-value"><?= (int)$totalExecutionCheckpoints ?></div></div>
    </div>
</div>

<!-- =======================================================
     MULTI-ASSET SAF CHECKLIST SELECTOR
     The checklist below remains exactly the same.
======================================================= -->

<div class="nleta-card">
    <div class="nleta-card-header">
        <h4 class="mb-1"><i class="bi bi-ui-checks-grid me-2"></i>Result Code Standard</h4>
        <div class="text-muted small">According to CHK-01 Rev.1 — select exactly one result for each checkpoint.</div>
    </div>
    <div class="legend-grid">
        <div class="legend-item"><div class="legend-code legend-c">C</div><div class="legend-description">Complied</div></div>
        <div class="legend-item"><div class="legend-code legend-nc">NC</div><div class="legend-description">Non-Complied</div></div>
        <div class="legend-item"><div class="legend-code legend-na">NA</div><div class="legend-description">Not Applicable</div></div>
        <div class="legend-item"><div class="legend-code legend-ca">CA</div><div class="legend-description">Conditional Acceptance</div></div>
    </div>
    <div class="rule-box">
        <strong>Mandatory Rules:</strong>
        <span class="ms-2">NC → Photograph OR Video REQUIRED</span>
        <span class="ms-2">NC → Engineering Remark REQUIRED</span>
        <span class="ms-2">CA → Technical Justification REQUIRED</span>
        <span class="ms-2">NC / CA → Video</span>
    </div>
</div>

<?php if ($isFinalSubmitted): ?>
<div class="pat-final-lock-banner">
    <div class="pat-final-lock-icon">
        <i class="bi bi-lock-fill"></i>
    </div>
    <div>
        <div class="pat-final-lock-title">Final Submitted — Checklist is View-Only</div>
        <div class="pat-final-lock-text">
            This Lift PAT checklist has already been submitted as part of the
            complete SAF Final Submit. The original checklist, results and
            evidence are locked and cannot be edited.
        </div>
    </div>
</div>
<?php endif; ?>

<form method="post" action="pat-checklist-save.php" enctype="multipart/form-data" id="patChecklistForm" novalidate>
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
<input type="hidden" name="uploaded_evidence_json" id="uploadedEvidenceJson" value="">

<?php if ($isFinalSubmitted): ?>
<fieldset class="pat-checklist-readonly" disabled aria-label="Final submitted Lift PAT checklist — view only">
<?php endif; ?>

<?php if (isset($sections['L'])): ?>
<div class="rope-assessment-card" id="ropeAssessmentCard">
<div class="rope-assessment-title">Rope Supplementary Assessment</div>
<div class="row g-3">
<div class="col-md-6"><label class="form-label fw-semibold" for="ropePhysicalCondition">Rope physical condition</label><select class="form-select" id="ropePhysicalCondition" name="rope_physical_condition"><option value="">Select condition</option><option value="Healthy" <?= $existingRopePhysicalCondition === 'Healthy' ? 'selected' : '' ?>>Healthy</option><option value="Not Healthy" <?= $existingRopePhysicalCondition === 'Not Healthy' ? 'selected' : '' ?>>Not Healthy</option></select><div class="form-text">Check for any cut, rusting, under-sized condition, exposed structure, damage, etc.</div></div>
<div class="col-md-6"><label class="form-label fw-semibold" for="ropeReplacementDate">Rope replacement date</label><input type="date" class="form-control" id="ropeReplacementDate" name="rope_replacement_date" value="<?= h($existingRopeReplacementDate) ?>" max="<?= h(date('Y-m-d')) ?>"><div class="form-text">Rope age is calculated automatically from this date to today.</div></div>
</div>
<div class="rope-rule-grid"><div class="rope-rule-item"><strong>≤ 3 years + Healthy</strong><br>C — Complied</div><div class="rope-rule-item"><strong>≤ 3 years + Not Healthy</strong><br>NC — Non-Complied</div><div class="rope-rule-item"><strong>&gt; 3 years + Healthy</strong><br>CA — Conditional Acceptance + justification</div><div class="rope-rule-item"><strong>&gt; 3 years + Not Healthy</strong><br>NC — Non-Complied</div></div>
<input type="hidden" id="ropeAcceptanceResult" name="rope_acceptance_result" value="<?= h($existingRopeAcceptanceResult) ?>">
<div id="ropeAssessmentStatus" class="rope-assessment-status">Select condition and replacement date to calculate the result.</div>
<div class="row g-3 mt-1" id="ropeEvidenceWrap" style="display:none">
<div class="col-md-6"><label class="form-label fw-semibold">Rope Evidence</label><input type="file" class="form-control form-control-sm file-input" name="rope_photo"><div class="form-text">For Rope NC / CA, upload either Photo OR Video.</div></div>
<div class="col-md-6"><label class="form-label fw-semibold">Rope Video Evidence <span class="optional-label"></span></label><input type="file" class="form-control form-control-sm file-input video-input" name="rope_video"><div class="form-text">For Rope NC / CA, Photo OR Video — any one is mandatory.</div></div>
</div>
<div class="mt-3" id="ropeTechnicalJustificationWrap" style="display:none"><label class="form-label fw-semibold" for="ropeTechnicalJustification">Technical justification <span class="text-danger">*</span></label><textarea class="form-control" id="ropeTechnicalJustification" name="rope_technical_justification" rows="3" placeholder="Enter technical justification for CA..."><?= h($existingRopeTechnicalJustification) ?></textarea></div>
<div class="saved-evidence-box" data-evidence-scope="rope" data-evidence-ref="ROPE-SUP-01"></div>
</div>
<?php endif; ?>

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
            <td><div class="checkpoint-text"><?= h($description) ?></div></td>
            <td>
                <div class="result-options">
                    <?php foreach (['C'=>'Complied','NC'=>'Non-Complied','NA'=>'Not Applicable','CA'=>'Conditional Acceptance'] as $code => $label): ?>
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
                            <label class="field-label">Photograph </label>
                            <input type="file" class="form-control form-control-sm file-input photo-input" name="photo[<?= h($ref) ?>]" data-photo-ref="<?= h($ref) ?>">
                            <span class="file-help">NC / CA → Photograph OR Video — at least ONE REQUIRED.</span>
                        </div>

                        <div class="field-group optional-video-field" data-ref="<?= h($ref) ?>">
                            <label class="field-label">
                                Video Evidence
                               
                            </label>
                            <input
                                type="file"
                                class="form-control form-control-sm file-input video-input"
                                name="video[<?= h($ref) ?>]"
                                data-video-ref="<?= h($ref) ?>"
                            >
                            <span class="file-help"> Any supported phone/browser video format — maximum 50 MB.</span>
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

                    <div
                        class="saved-evidence-box"
                        data-evidence-scope="fixed"
                        data-evidence-ref="<?= h($ref) ?>"
                    ></div>

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

    <div class="floor-bulk-area">
        <div class="floor-bulk-heading"><i class="bi bi-check2-all me-1"></i>Select All Result Floor-wise</div>
        <div class="floor-bulk-help">Select a result for one floor to apply it to all 8 landing-door checkpoints on that floor. Each floor-wise checkpoint now has its own priority. NC still requires a photograph and engineering remark; CA requires technical justification.</div>
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
            <th style="width:130px">D2 ID</th>
            <th style="width:70px">Priority</th>
            <th>Checkpoint</th>
            <th style="width:290px">Status</th>
            <th style="width:330px">Remark / Technical Justification / Photograph</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($landingFloors as $floorIndex => $floorLabel): ?>
            <?php foreach ($floorCheckpointDefinitions as $floorKey => $floorDefinition): ?>
            <?php
            $floorDescription = (string)($floorDefinition['description'] ?? '');
            $floorPriority = (string)($floorDefinition['priority'] ?? 'P3');

            $savedFloorItem =
                is_array($existingFloorResults[$floorLabel][$floorKey] ?? null)
                    ? $existingFloorResults[$floorLabel][$floorKey]
                    : [];

            $savedFloorResult =
                strtoupper(trim((string)($savedFloorItem['result'] ?? '')));

            if (!in_array($savedFloorResult, ['C','NC','NA','CA'], true)) {
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
                    <span class="ref-code">D2-<?= h($floorLabel) ?></span>
                </td>
                <?php endif; ?>

                <td>
                    <span class="priority priority-<?= h(strtolower($floorPriority)) ?>"><?= h($floorPriority) ?></span>
                </td>

                <td>
                    <div class="checkpoint-text"><?= h($floorDescription) ?></div>
                </td>

                <td>
                    <div class="result-options">
                        <?php foreach (['C','NC','NA','CA'] as $code): ?>
                        <label class="result-option">
                            <input
                                type="radio"
                                name="floor_result[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                value="<?= h($code) ?>"
                                data-floor="<?= h($floorLabel) ?>"
                                data-floor-key="<?= h($floorKey) ?>"
                                <?= $savedFloorResult === $code ? 'checked' : '' ?>
                            >
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
                                <label class="field-label">Photograph </label>
                                <input
                                    type="file"
                                    class="form-control form-control-sm file-input floor-photo-input"
                                    name="floor_photo[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                >
                                <span class="file-help">NC / CA → Photograph OR Video — at least ONE REQUIRED. Photo max 10 MB.</span>
                            </div>

                            <div class="field-group optional-floor-video-field">
                                <label class="field-label">
                                    Video Evidence
                                    
                                </label>
                                <input
                                    type="file"
                                    class="form-control form-control-sm file-input floor-video-input"
                                    name="floor_video[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                >
                                <span class="file-help"> Any supported phone/browser video format — maximum 50 MB.</span>
                            </div>

                            <div class="dynamic-required floor-remark-field">
                                <div class="field-group">
                                    <label class="field-label">Engineering Remark <span class="required">*</span></label>
                                    <textarea
                                        class="remark-input floor-remark-input"
                                        name="floor_remark[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                        maxlength="2000"
                                    ><?= h($savedFloorRemark) ?></textarea>
                                </div>
                            </div>

                            <div class="dynamic-required floor-justification-field">
                                <div class="field-group">
                                    <label class="field-label">Technical Justification <span class="required">*</span></label>
                                    <textarea
                                        class="justification-input floor-justification-input"
                                        name="floor_technical_justification[<?= h($floorLabel) ?>][<?= h($floorKey) ?>]"
                                        maxlength="3000"
                                    ><?= h($savedFloorJustification) ?></textarea>
                                </div>
                            </div>
                        </div>

                        <div
                            class="saved-evidence-box"
                            data-evidence-scope="floor"
                            data-evidence-ref="<?= h($floorLabel) ?>"
                            data-evidence-floor-key="<?= h($floorKey) ?>"
                        ></div>

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
            <td><div class="checkpoint-text"><?= h($description) ?></div></td>
            <td>
                <div class="result-options">
                    <?php foreach (['C','NC','NA','CA'] as $code): ?>
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
                            <label class="field-label">Photograph </label>
                            <input type="file" class="form-control form-control-sm file-input photo-input" name="photo[<?= h($ref) ?>]" data-photo-ref="<?= h($ref) ?>">
                            <span class="file-help">NC / CA → Photograph OR Video — at least ONE REQUIRED. Photo max 10 MB.</span>
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
        <div class="text-muted small">129 active fixed PAT checkpoints + dynamic D2 landing rows</div>
    </div>
    <div class="summary-grid summary-grid-totals">
        <div class="summary-box">
            <div class="summary-label">Total Execution Checkpoints</div>
            <div class="summary-value summary-total" id="summaryTotal"><?= (int)$totalExecutionCheckpoints ?></div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Total Fixed Points</div>
            <div class="summary-value summary-total" id="summaryFixed">129</div>
        </div>
        <div class="summary-box">
            <div class="summary-label">Floor-wise Points</div>
            <div class="summary-value summary-total" id="summaryFloor"><?= (int)$totalFloorExecutionRows ?></div>
        </div>
    </div>

    <div class="summary-grid summary-grid-results">
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
        <?php foreach (['PASS','CONDITIONAL PASS','FAIL'] as $status): ?>
        <label class="final-option">
            <input type="radio" name="final_status" value="<?= h($status) ?>" <?= $existingFinalStatus === $status ? 'checked' : '' ?>>
            <?= h($status) ?>
        </label>
        <?php endforeach; ?>
    </div>

    <div class="final-fields">
        <div class="final-field">
            <label>Inspector Name</label>
            <input type="text" name="inspector_signature" value="<?= h($existingInspectorSignature) ?>" placeholder="Inspector name" maxlength="255">
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

<?php if ($isFinalSubmitted): ?>
</fieldset>
<?php endif; ?>

<div class="action-bar">
    <div class="action-content">
        <div>
            <strong>CHK-01 Rev.1</strong>
            <span class="text-muted ms-2">129 Fixed + <?= (int)$totalFloorExecutionRows ?> Floor-wise = <?= (int)$totalExecutionCheckpoints ?> Total</span>
            <span id="draftStatus" class="draft-status" aria-live="polite"></span>
        </div>
        <div>
            <a href="inspections.php" class="btn btn-outline-secondary me-2">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <?php if ($isFinalSubmitted): ?>
            <span class="badge text-bg-success align-self-center px-3 py-2">
                <i class="bi bi-lock-fill me-1"></i>Final Submitted — View Only
            </span>
            <?php else: ?>
            <button type="reset" class="btn btn-outline-warning me-2" id="resetChecklist">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
            </button>
            <button type="submit" class="btn btn-primary me-2" id="saveAssetNext">
                <i class="bi bi-arrow-right-circle me-1"></i>Save Asset &amp; Continue
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

</form>
</div>
</div>

<script>
(function () {
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
    const isFinalSubmitted = <?= $isFinalSubmitted ? 'true' : 'false' ?>;

    /*
     * Evidence already stored in the database for this asset.
     * File inputs themselves cannot be restored by the browser.
     */
    const savedEvidence = <?= json_encode(
        $savedEvidenceForUi,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ) ?>;

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

    function evidenceUrl(path) {
        const value = String(path || '').trim();

        if (!value) return '#';

        if (/^(https?:)?\/\//i.test(value) || value.charAt(0) === '/') {
            return value;
        }

        return '../' + value.replace(/^\/+/, '');
    }

    function getSavedEvidence(scope, ref, floorKey) {
        let currentResult = '';

        if (scope === 'rope') {
            currentResult =
                form.querySelector('#ropeAcceptanceResult')?.value || '';
            if (!['NC', 'CA'].includes(currentResult)) {
                return {photo:'', video:''};
            }
            return savedEvidence['ROPE-SUP-01'] || {photo:'', video:''};
        }

        if (scope === 'floor') {
            const row = form.querySelector(
                '.floor-check-row[data-floor="' + esc(ref) + '"][data-floor-key="' + esc(floorKey) + '"]'
            );
            currentResult =
                row?.querySelector('input[type="radio"]:checked')?.value || '';
            if (!['NC', 'CA'].includes(currentResult)) {
                return {photo:'', video:''};
            }

            const key = 'FLOOR-' + ref + '-' + floorKey;
            return savedEvidence[key] || {photo:'', video:''};
        }

        currentResult = getResult(ref);
        if (!['NC', 'CA'].includes(currentResult)) {
            return {photo:'', video:''};
        }

        return savedEvidence[ref] || {photo:'', video:''};
    }

    function renderSavedEvidenceBox(box, evidence) {
        if (!box) return;

        const photo = String(evidence?.photo || '').trim();
        const video = String(evidence?.video || '').trim();

        if (!photo && !video) {
            box.innerHTML = '';
            box.classList.remove('show');
            return;
        }

        let html =
            '<div class="saved-evidence-title">✓ Evidence saved on server</div>';

        if (photo) {
            html +=
                '<div class="saved-evidence-item">' +
                    '<span class="saved-evidence-meta">📷 Photograph saved</span>' +
                    '<a href="' + evidenceUrl(photo) + '" target="_blank" rel="noopener">View</a>' +
                '</div>';
        }

        if (video) {
            html +=
                '<div class="saved-evidence-item">' +
                    '<span class="saved-evidence-meta">🎥 Video saved</span>' +
                    '<a href="' + evidenceUrl(video) + '" target="_blank" rel="noopener">View</a>' +
                '</div>';
        }

        html +=
            '<div class="saved-evidence-replace">' +
            'Select a new file above to replace this evidence.' +
            '</div>';

        box.innerHTML = html;
        box.classList.add('show');
    }

    function renderAllSavedEvidence() {
        form.querySelectorAll('.saved-evidence-box').forEach(function (box) {
            const scope = box.dataset.evidenceScope || 'fixed';
            const ref = box.dataset.evidenceRef || '';
            const floorKey = box.dataset.evidenceFloorKey || '';

            renderSavedEvidenceBox(
                box,
                getSavedEvidence(scope, ref, floorKey)
            );
        });
    }

    function setClientSavedEvidence(scope, ref, type, path, floorKey) {
        let key = ref;

        if (scope === 'rope') {
            key = 'ROPE-SUP-01';
        } else if (scope === 'floor') {
            key = 'FLOOR-' + ref + '-' + floorKey;
        }

        if (!savedEvidence[key]) {
            savedEvidence[key] = {photo:'', video:''};
        }

        savedEvidence[key][type] = path;

        form.querySelectorAll('.saved-evidence-box').forEach(function (box) {
            if (
                (box.dataset.evidenceScope || '') !== scope ||
                (box.dataset.evidenceRef || '') !== ref ||
                (scope === 'floor' &&
                    (box.dataset.evidenceFloorKey || '') !== floorKey)
            ) {
                return;
            }

            renderSavedEvidenceBox(box, savedEvidence[key]);
        });
    }

    function updateFixedCheckpoint(ref) {
        const row = document.querySelector(
            'tr[data-checkpoint="' + esc(ref) + '"]'
        );

        if (!row) return;

        const result = getResult(ref);
        const savedBox = row.querySelector('.saved-evidence-box');
        if (savedBox) {
            renderSavedEvidenceBox(
                savedBox,
                getSavedEvidence('fixed', ref, '')
            );
        }

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
                note.textContent = 'NC — Photograph OR Video and Engineering Remark are mandatory.';
                note.className = 'small text-danger mt-2 status-note';
            }
            return;
        }

        if (result === 'CA') {
            if (evidence) evidence.classList.add('show');
            if (justificationField) justificationField.classList.add('show');
            if (justification) justification.required = true;
            if (photoRequired) photoRequired.style.display = 'inline';
            row.classList.add('table-warning');

            if (note) {
                note.textContent = 'CA — Photograph OR Video and Technical Justification are mandatory.';
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

        const savedBox = row.querySelector('.saved-evidence-box');
        if (savedBox) {
            renderSavedEvidenceBox(
                savedBox,
                getSavedEvidence(
                    'floor',
                    row.dataset.floor || '',
                    row.dataset.floorKey || ''
                )
            );
        }

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
                note.textContent = 'NC — Photograph OR Video and Engineering Remark are mandatory.';
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
                note.textContent = 'CA — Photograph OR Video and Technical Justification are mandatory.';
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
        let c = 0, nc = 0, na = 0, ca = 0;
        let fixedSelected = 0;
        let floorSelected = 0;

        /* Fixed PAT-001 ... PAT-128 */
        form.querySelectorAll('input[name^="result["]:checked').forEach(function (input) {
            fixedSelected++;

            if (input.value === 'C') c++;
            if (input.value === 'NC') nc++;
            if (input.value === 'NA') na++;
            if (input.value === 'CA') ca++;
        });

        /* Dynamic D2 floor-wise rows */
        form.querySelectorAll('.floor-check-row input[type="radio"]:checked').forEach(function (input) {
            floorSelected++;

            if (input.value === 'C') c++;
            if (input.value === 'NC') nc++;
            if (input.value === 'NA') na++;
            if (input.value === 'CA') ca++;
        });

        const set = (id, value) => {
            const el = document.getElementById(id);
            if (el) el.textContent = value;
        };

        const fixedTotal = 129;
        const floorTotal = document.querySelectorAll('.floor-check-row').length;
        const executionTotal = fixedTotal + floorTotal;

        set('summaryTotal', executionTotal);
        set('summaryFixed', fixedTotal);
        set('summaryFloor', floorTotal);
        set('summaryC', c);
        set('summaryNC', nc);
        set('summaryNA', na);
        set('summaryCA', ca);

        const fixedLabel = document.querySelector('#summaryFixed')?.parentElement?.querySelector('.summary-label');
        if (fixedLabel) {
            fixedLabel.textContent = 'Total Fixed Points';
        }
    }

    function collectDraft() {
        const draft = {
            version: 'CHK-01-REV1-DOCX-129-ROPE-SUPPLEMENT',
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

        form.querySelectorAll('input[name^="result["]:checked').forEach(function (input) {
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

        form.querySelectorAll('.floor-check-row').forEach(function (row) {
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

        form.querySelectorAll('textarea[name^="section_remarks["]').forEach(function (textarea) {
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
        draft.rope_physical_condition = form.querySelector('#ropePhysicalCondition')?.value || '';
        draft.rope_replacement_date = form.querySelector('#ropeReplacementDate')?.value || '';
        draft.rope_acceptance_result = form.querySelector('#ropeAcceptanceResult')?.value || '';
        draft.rope_technical_justification = form.querySelector('#ropeTechnicalJustification')?.value || '';

        return draft;
    }

    function saveDraft() {
        if (restoring || isFinalSubmitted) return;

        try {
            localStorage.setItem(draftKey, JSON.stringify(collectDraft()));
            setDraftStatus('✓ Draft saved', 'saved');
        } catch (e) {
            console.error(e);
            setDraftStatus('Draft storage unavailable', 'error');
        }
    }

    function scheduleDraftSave() {
        if (restoring || isFinalSubmitted) return;

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
            try { localStorage.removeItem(draftKey); } catch (ignore) {}
            return false;
        }

        if (!draft || String(draft.job_id) !== String(jobId)) {
            return false;
        }

        restoring = true;

        try {
            if (draft.results && typeof draft.results === 'object') {
                Object.keys(draft.results).forEach(function (ref) {
                    const item = draft.results[ref] || {};
                    const result = item.result || '';

                    if (['C','NC','NA','CA'].includes(result)) {
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
                Object.keys(draft.floor_checklist).forEach(function (floor) {
                    const floorData = draft.floor_checklist[floor];
                    if (!floorData || typeof floorData !== 'object') return;

                    Object.keys(floorData).forEach(function (key) {
                        const item = floorData[key] || {};
                        const row = form.querySelector(
                            '.floor-check-row[data-floor="' + esc(floor) + '"][data-floor-key="' + esc(key) + '"]'
                        );
                        if (!row) return;

                        if (['C','NC','NA','CA'].includes(item.result || '')) {
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
                Object.keys(draft.section_remarks).forEach(function (section) {
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

            if (['PASS','CONDITIONAL PASS','FAIL'].includes(draft.final_status || '')) {
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

            const rc = form.querySelector('#ropePhysicalCondition');
            if (rc) rc.value = draft.rope_physical_condition || '';
            const rd = form.querySelector('#ropeReplacementDate');
            if (rd) rd.value = draft.rope_replacement_date || '';
            const rj = form.querySelector('#ropeTechnicalJustification');
            if (rj) rj.value = draft.rope_technical_justification || '';
            updateRopeAssessment();

        } finally {
            restoring = false;
        }

        form.querySelectorAll('input[name^="result["]:checked').forEach(function (input) {
            updateFixedCheckpoint(input.dataset.ref);
        });

        form.querySelectorAll('.floor-check-row').forEach(updateFloorRow);

        updateSummary();
        setDraftStatus('✓ Draft restored', 'saved');

        return true;
    }

    /*
     * RESULT CHANGE
     * ---------------------------------------------------------
     * IMPORTANT:
     * NC / CA evidence fields must become editable immediately.
     * Do NOT disable those fields during normal interaction.
     * They are disabled only immediately before POST, when
     * syncSubmissionInputStates() prepares the final payload.
     */
    form.querySelectorAll('input[name^="result["]').forEach(function (input) {
        input.addEventListener('change', function () {
            updateFixedCheckpoint(input.dataset.ref);
            updateSummary();

            /* Keep the selected NC/CA fields enabled for typing/upload. */
            const row = input.closest('tr[data-checkpoint]');
            if (row) {
                row.querySelectorAll(
                    'input[type="file"], textarea[data-remark-ref], textarea[data-justification-ref]'
                ).forEach(function (field) {
                    field.disabled = false;
                });
            }

            scheduleDraftSave();
        });
    });

    form.querySelectorAll('.floor-check-row input[type="radio"]').forEach(function (input) {
        input.addEventListener('change', function () {
            const row = input.closest('.floor-check-row');

            updateFloorRow(row);

            /* Keep floor NC/CA fields enabled for immediate entry. */
            if (row) {
                row.querySelectorAll(
                    'input[type="file"], .floor-remark-input, .floor-justification-input'
                ).forEach(function (field) {
                    field.disabled = false;
                });
            }

            scheduleDraftSave();
        });
    });

    form.querySelectorAll('.bulk-result-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const sectionCard = button.closest('.section-card');
            const result = button.dataset.result || '';

            if (!sectionCard || !['C','NC','NA','CA'].includes(result)) return;

            let count = 0;

            sectionCard.querySelectorAll('tr[data-checkpoint]').forEach(function (row) {
                const ref = row.dataset.checkpoint || '';
                const radio = row.querySelector(
                    'input[name="result[' + esc(ref) + ']"][value="' + esc(result) + '"]'
                );

                if (radio) {
                    radio.checked = true;
                    updateFixedCheckpoint(ref);

                    /* Bulk NC/CA must also remain immediately editable. */
                    row.querySelectorAll(
                        'input[type="file"], textarea[data-remark-ref], textarea[data-justification-ref]'
                    ).forEach(function (field) {
                        field.disabled = false;
                    });

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
    form.querySelectorAll('.floor-bulk-result-btn').forEach(function (button) {
        button.addEventListener('click', function () {
            const floor = button.dataset.floor || '';
            const result = button.dataset.result || '';

            if (!floor || !['C','NC','NA','CA'].includes(result)) return;

            let count = 0;

            form.querySelectorAll('.floor-check-row').forEach(function (row) {
                if ((row.dataset.floor || '') !== floor) return;

                const radio = row.querySelector(
                    'input[type="radio"][value="' + esc(result) + '"]'
                );

                if (radio) {
                    radio.checked = true;
                    updateFloorRow(row);

                    /* Bulk floor NC/CA must remain immediately editable. */
                    row.querySelectorAll(
                        'input[type="file"], .floor-remark-input, .floor-justification-input'
                    ).forEach(function (field) {
                        field.disabled = false;
                    });

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

    form.querySelectorAll('textarea, input[type="text"]').forEach(function (input) {
        input.addEventListener('input', scheduleDraftSave);
        input.addEventListener('change', scheduleDraftSave);
    });

    form.querySelectorAll('input[name="final_status"]').forEach(function (input) {
        input.addEventListener('change', scheduleDraftSave);
    });

    form.querySelectorAll('input[type="file"]').forEach(function (input) {
        input.required = false;

        input.addEventListener('change', async function () {
            input.classList.remove('is-invalid');

            if (!input.files || !input.files.length) return;

            const file = input.files[0];
            const maxSize = 50 * 1024 * 1024;

            if (file.size > maxSize) {
                input.value = '';
                input.classList.add('is-invalid');
                alert('Evidence file cannot exceed 50 MB.');
                return;
            }

            const descriptor = getEvidenceDescriptor(input);

            if (!validateEvidenceFileTypeClient(file, descriptor.type)) {
                input.value = '';
                input.classList.add('is-invalid');
                alert(
                    descriptor.type === 'photo'
                        ? 'Photograph field accepts image/photo files only.'
                        : 'Video field accepts video files only.'
                );
                return;
            }

            let result = '';

            if (descriptor.scope === 'rope') {
                result =
                    form.querySelector('#ropeAcceptanceResult')?.value || '';
            } else if (descriptor.scope === 'floor') {
                const row = input.closest('.floor-check-row');
                result =
                    row?.querySelector('input[type="radio"]:checked')?.value || '';
            } else {
                result = getResult(descriptor.ref);
            }

            if (!['NC', 'CA'].includes(result)) {
                input.value = '';
                alert(
                    'Evidence can only be uploaded when the checkpoint result is NC or CA.'
                );
                return;
            }

            try {
                setDraftStatus(
                    'Uploading selected evidence…',
                    'saving'
                );

                await uploadSingleEvidence(input);

                /*
                 * The actual file is already on the server and in DB.
                 * Clear the browser file input so the same file can be
                 * selected again later if needed.
                 */
                input.value = '';

                setDraftStatus(
                    '✓ Evidence saved on server • refresh/back safe',
                    'saved'
                );

                scheduleDraftSave();
            } catch (error) {
                console.error(error);

                input.classList.add('is-invalid');

                setDraftStatus(
                    'Evidence upload failed',
                    'error'
                );

                alert(
                    'Evidence upload failed:\n\n' +
                    (error?.message || 'Unknown upload error.')
                );
            }
        });
    });

    renderAllSavedEvidence();


    /*
     * =========================================================
     * PHP max_input_vars PROTECTION
     * ---------------------------------------------------------
     * The PAT checklist contains many radio, remark, justification
     * and evidence fields. PHP counts submitted multipart fields,
     * including empty file controls on many PHP configurations.
     *
     * Only controls relevant to the selected result are submitted:
     *   C / NA -> no evidence/remark/justification fields
     *   NC     -> photo + optional video + engineering remark
     *   CA     -> optional photo/video + technical justification
     *
     * This keeps the POST payload small and prevents later
     * checkpoints such as PAT-025 / PAT-026 and D2 floor rows from
     * being truncated by PHP's max_input_vars limit.
     * =========================================================
     */
    /*
     * =========================================================
     * ONE-FILE-AT-A-TIME EVIDENCE UPLOADER
     * =========================================================
     * A normal multipart form can hit PHP's max_file_uploads
     * limit when a checklist has many NC/CA evidence files.
     *
     * We upload every selected evidence file separately first.
     * The final checklist request contains only JSON paths.
     */
    const uploadedEvidence = {
        fixed: {},
        floor: {},
        rope: {}
    };

    let evidenceUploadBusy = false;

    function getCsrfToken() {
        return form.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function setUploadedEvidence(scope, ref, type, path, floorKey) {
        if (scope === 'fixed') {
            if (!uploadedEvidence.fixed[ref]) uploadedEvidence.fixed[ref] = {};
            uploadedEvidence.fixed[ref][type] = path;
            return;
        }

        if (scope === 'rope') {
            uploadedEvidence.rope[type] = path;
            return;
        }

        if (scope === 'floor') {
            if (!uploadedEvidence.floor[ref]) uploadedEvidence.floor[ref] = {};
            if (!uploadedEvidence.floor[ref][floorKey]) {
                uploadedEvidence.floor[ref][floorKey] = {};
            }
            uploadedEvidence.floor[ref][floorKey][type] = path;
        }
    }

    function getEvidenceDescriptor(input) {
        const type =
            input.classList.contains('video-input') ||
            input.classList.contains('floor-video-input') ||
            input.name === 'rope_video'
                ? 'video'
                : 'photo';

        if (input.name === 'rope_photo' || input.name === 'rope_video') {
            return {
                scope: 'rope',
                ref: 'ROPE-SUP-01',
                floorKey: '',
                type: type
            };
        }

        if (input.classList.contains('floor-photo-input') ||
            input.classList.contains('floor-video-input')) {
            const row = input.closest('.floor-check-row');
            return {
                scope: 'floor',
                ref: row?.dataset.floor || '',
                floorKey: row?.dataset.floorKey || '',
                type: type
            };
        }

        const row = input.closest('tr[data-checkpoint]');
        return {
            scope: 'fixed',
            ref: row?.dataset.checkpoint || '',
            floorKey: '',
            type: type
        };
    }

    function validateEvidenceFileTypeClient(file, type) {
        const name = String(file?.name || '').toLowerCase();
        const mime = String(file?.type || '').toLowerCase();
        const ext = name.includes('.') ? name.split('.').pop() : '';

        const photoExtensions = new Set([
            'jpg','jpeg','jpe','png','webp','heic','heif','avif','gif',
            'bmp','tif','tiff','svg','ico','jfif','raw','dng','cr2','cr3',
            'nef','nrw','arw','orf','rw2','raf','pef','srw','3fr','iiq'
        ]);

        const videoExtensions = new Set([
            'mp4','m4v','mov','avi','mkv','webm','3gp','3g2','wmv','flv',
            'mpeg','mpg','mpe','mts','m2ts','ts','vob','ogv','ogg','asf',
            'rm','rmvb','dv','f4v'
        ]);

        if (type === 'photo') {
            return mime.startsWith('image/') || photoExtensions.has(ext);
        }

        if (type === 'video') {
            return mime.startsWith('video/') || videoExtensions.has(ext);
        }

        return false;
    }

    async function uploadSingleEvidence(input) {
        if (!input.files || !input.files.length) return true;

        const file = input.files[0];
        const descriptor = getEvidenceDescriptor(input);

        if (!descriptor.ref || !descriptor.type) {
            throw new Error('Unable to identify an evidence checkpoint.');
        }

        if (!validateEvidenceFileTypeClient(file, descriptor.type)) {
            throw new Error(
                descriptor.type === 'photo'
                    ? 'Photograph field accepts image/photo files only.'
                    : 'Video field accepts video files only.'
            );
        }

        let evidenceResult = '';

        if (descriptor.scope === 'rope') {
            evidenceResult =
                form.querySelector('#ropeAcceptanceResult')?.value || '';
        } else if (descriptor.scope === 'floor') {
            const row = input.closest('.floor-check-row');
            evidenceResult =
                row?.querySelector('input[type="radio"]:checked')?.value || '';
        } else {
            evidenceResult = getResult(descriptor.ref);
        }

        if (!['NC', 'CA'].includes(evidenceResult)) {
            throw new Error(
                descriptor.ref + ': Evidence can only be uploaded for NC or CA.'
            );
        }

        const formData = new FormData();
        formData.append('upload_only', '1');
        formData.append('job_id', jobId);
        formData.append(
            'assignment_id',
            form.querySelector('input[name="assignment_id"]')?.value || '0'
        );
        formData.append('csrf_token', getCsrfToken());
        formData.append('evidence_type', descriptor.type);
        formData.append('evidence_result', evidenceResult);

        let evidenceRef = descriptor.ref;

        if (descriptor.scope === 'floor') {
            evidenceRef =
                'FLOOR-' + descriptor.ref + '-' + descriptor.floorKey;
        }

        formData.append('evidence_ref', evidenceRef);
        formData.append('evidence_file', file, file.name);

        const response = await fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        let data = null;

        try {
            data = await response.json();
        } catch (e) {
            throw new Error(
                'Server returned an invalid evidence-upload response.'
            );
        }

        if (!response.ok || !data || data.ok !== true || !data.path) {
            throw new Error(
                data?.message ||
                ('Evidence upload failed for ' + evidenceRef + '.')
            );
        }

        setUploadedEvidence(
            descriptor.scope,
            descriptor.scope === 'floor' ? descriptor.ref : descriptor.ref,
            descriptor.type,
            data.path,
            descriptor.floorKey
        );

        setClientSavedEvidence(
            descriptor.scope,
            descriptor.ref,
            descriptor.type,
            data.path,
            descriptor.floorKey
        );

        return true;
    }

    function selectedEvidenceInputs() {
        const inputs = [];

        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            if (input.disabled) return;
            if (!input.files || !input.files.length) return;

            /*
             * Upload only evidence that belongs to an NC/CA item.
             * This avoids attaching an unused file to a C/NA checkpoint.
             */
            const descriptor = getEvidenceDescriptor(input);

            if (descriptor.scope === 'rope') {
                const ropeResultValue =
                    form.querySelector('#ropeAcceptanceResult')?.value || '';

                if (!['NC', 'CA'].includes(ropeResultValue)) {
                    return;
                }
            } else if (descriptor.scope === 'floor') {
                const row = input.closest('.floor-check-row');
                const result =
                    row?.querySelector('input[type="radio"]:checked')?.value || '';

                if (!['NC', 'CA'].includes(result)) {
                    return;
                }
            } else {
                const result = getResult(descriptor.ref);

                if (!['NC', 'CA'].includes(result)) {
                    return;
                }
            }

            inputs.push(input);
        });

        return inputs;
    }

    async function uploadAllEvidenceFiles() {
        if (evidenceUploadBusy) return false;

        const inputs = selectedEvidenceInputs();

        if (!inputs.length) {
            const hidden = document.getElementById('uploadedEvidenceJson');
            if (hidden) {
                hidden.value = JSON.stringify(uploadedEvidence);
            }
            return true;
        }

        evidenceUploadBusy = true;

        try {
            for (let i = 0; i < inputs.length; i++) {
                const input = inputs[i];

                setDraftStatus(
                    'Uploading evidence ' + (i + 1) + ' of ' + inputs.length + '…',
                    'saving'
                );

                await uploadSingleEvidence(input);
            }

            const hidden = document.getElementById('uploadedEvidenceJson');

            if (hidden) {
                hidden.value = JSON.stringify(uploadedEvidence);
            }

            return true;
        } finally {
            evidenceUploadBusy = false;
        }
    }

    function syncSubmissionInputStates() {
        /* Fixed PAT + D3 rows */
        form.querySelectorAll('tr[data-checkpoint]').forEach(function (row) {
            const ref = row.dataset.checkpoint || '';
            const resultInput = row.querySelector(
                'input[name="result[' + esc(ref) + ']"]:checked'
            );
            const result = resultInput ? resultInput.value : '';

            row.querySelectorAll(
                'textarea[data-remark-ref], textarea[data-justification-ref]'
            ).forEach(function (input) {
                input.disabled = true;
            });

            if (result === 'NC') {
                const photo = row.querySelector('input.photo-input');
                const video = row.querySelector('input.video-input');
                const remark = row.querySelector('textarea[data-remark-ref]');

                if (photo) photo.disabled = false;
                if (video) video.disabled = false;
                if (remark) remark.disabled = false;
            } else if (result === 'CA') {
                const photo = row.querySelector('input.photo-input');
                const video = row.querySelector('input.video-input');
                const justification = row.querySelector(
                    'textarea[data-justification-ref]'
                );

                if (photo) photo.disabled = false;
                if (video) video.disabled = false;
                if (justification) justification.disabled = false;
            }
        });

        /* Dynamic D2 floor rows */
        form.querySelectorAll('.floor-check-row').forEach(function (row) {
            const checked = row.querySelector(
                'input[type="radio"]:checked'
            );
            const result = checked ? checked.value : '';

            row.querySelectorAll(
                '.floor-remark-input, .floor-justification-input'
            ).forEach(function (input) {
                input.disabled = true;
            });

            if (result === 'NC') {
                const photo = row.querySelector('.floor-photo-input');
                const video = row.querySelector('.floor-video-input');
                const remark = row.querySelector('.floor-remark-input');

                if (photo) photo.disabled = false;
                if (video) video.disabled = false;
                if (remark) remark.disabled = false;
            } else if (result === 'CA') {
                const photo = row.querySelector('.floor-photo-input');
                const video = row.querySelector('.floor-video-input');
                const justification = row.querySelector(
                    '.floor-justification-input'
                );

                if (photo) photo.disabled = false;
                if (video) video.disabled = false;
                if (justification) justification.disabled = false;
            }
        });
    }

    function validateForm() {
        const errors = [];

        form.querySelectorAll('tr[data-checkpoint]').forEach(function (row) {
            const ref = row.dataset.checkpoint || '';
            const result = getResult(ref);

            if (!result) {
                errors.push(ref + ': Result is required.');
                return;
            }

            if (result === 'NC' || result === 'CA') {
                const photo = row.querySelector('input.photo-input');
                const video = row.querySelector('input.video-input');
                const hasPhoto =
                    !!(photo && photo.files && photo.files.length > 0) ||
                    !!String(savedEvidence[ref]?.photo || '').trim();
                const hasVideo =
                    !!(video && video.files && video.files.length > 0) ||
                    !!String(savedEvidence[ref]?.video || '').trim();

                if (!hasPhoto && !hasVideo) {
                    errors.push(ref + ': Upload either Photograph OR Video for ' + result + '.');
                    if (photo) photo.classList.add('is-invalid');
                    if (video) video.classList.add('is-invalid');
                }

                if (result === 'NC') {
                    const remark = row.querySelector('textarea[data-remark-ref]');
                    if (!remark || remark.value.trim() === '') {
                        errors.push(ref + ': Engineering Remark is required for NC.');
                        if (remark) remark.classList.add('is-invalid');
                    }
                }

                if (result === 'CA') {
                    const justification = row.querySelector('textarea[data-justification-ref]');
                    if (!justification || justification.value.trim() === '') {
                        errors.push(ref + ': Technical Justification is required for CA.');
                        if (justification) justification.classList.add('is-invalid');
                    }
                }
            }
        });

        form.querySelectorAll('.floor-check-row').forEach(function (row) {
            const floor = row.dataset.floor || '';
            const key = row.dataset.floorKey || '';
            const checked = row.querySelector('input[type="radio"]:checked');
            const result = checked ? checked.value : '';

            if (!result) {
                errors.push(floor + ' / ' + key + ': Status is required.');
                return;
            }

            if (result === 'NC' || result === 'CA') {
                const photo = row.querySelector('.floor-photo-input');
                const video = row.querySelector('.floor-video-input');
                const evidenceKey = 'FLOOR-' + floor + '-' + key;
                const hasPhoto =
                    !!(photo && photo.files && photo.files.length > 0) ||
                    !!String(savedEvidence[evidenceKey]?.photo || '').trim();
                const hasVideo =
                    !!(video && video.files && video.files.length > 0) ||
                    !!String(savedEvidence[evidenceKey]?.video || '').trim();

                if (!hasPhoto && !hasVideo) {
                    errors.push(floor + ' / ' + key + ': Upload either Photograph OR Video for ' + result + '.');
                    if (photo) photo.classList.add('is-invalid');
                    if (video) video.classList.add('is-invalid');
                }

                if (result === 'NC') {
                    const remark = row.querySelector('.floor-remark-input');
                    if (!remark || remark.value.trim() === '') {
                        errors.push(floor + ' / ' + key + ': Engineering Remark is required for NC.');
                        if (remark) remark.classList.add('is-invalid');
                    }
                }

                if (result === 'CA') {
                    const justification = row.querySelector('.floor-justification-input');
                    if (!justification || justification.value.trim() === '') {
                        errors.push(floor + ' / ' + key + ': Technical Justification is required for CA.');
                        if (justification) justification.classList.add('is-invalid');
                    }
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
     * Multi-asset checklist mode.
     * This page saves the current asset checklist only.
     * Complete SAF submission is handled from inspections.php.
     */
    if (saveAssetNextButton) {
        saveAssetNextButton.addEventListener('click', function () {
            requestedSubmitMode = 'asset';

            if (saveModeInput) {
                saveModeInput.value = 'asset';
            }
        });
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        updateRopeAssessment();
        if (ropeCondition && ropeDate && (ropeCondition.value || ropeDate.value)) {
            if (!ropeCondition.value || !ropeDate.value || !ropeResult.value) { event.preventDefault(); alert('Please complete Rope physical condition and replacement date correctly.'); return false; }
            if ((ropeResult.value === 'NC' || ropeResult.value === 'CA')) {
                const ropePhoto = form.querySelector('input[name="rope_photo"]');
                const ropeVideo = form.querySelector('input[name="rope_video"]');
                const hasRopePhoto =
                    !!(ropePhoto && ropePhoto.files && ropePhoto.files.length > 0) ||
                    !!String(savedEvidence['ROPE-SUP-01']?.photo || '').trim();
                const hasRopeVideo =
                    !!(ropeVideo && ropeVideo.files && ropeVideo.files.length > 0) ||
                    !!String(savedEvidence['ROPE-SUP-01']?.video || '').trim();
                if (!hasRopePhoto && !hasRopeVideo) {
                    event.preventDefault();
                    alert('For Rope ' + ropeResult.value + ', upload either Photo OR Video.');
                    if (ropePhoto) ropePhoto.focus();
                    return false;
                }
            }
            if (ropeResult.value === 'CA' && ropeJustification && !ropeJustification.value.trim()) { event.preventDefault(); alert('Technical justification is required when Rope result is CA.'); ropeJustification.focus(); return false; }
        }

        if (isFinalSubmitted) {
            event.preventDefault();
            alert('This Lift PAT checklist is locked because Final Submit has already been completed.');
            return;
        }

        if (saveModeInput) {
            saveModeInput.value = requestedSubmitMode;
        }

        saveDraft();

        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
        });

        /*
         * Disable irrelevant empty controls BEFORE browser submission.
         * This is the main protection against PHP max_input_vars truncation.
         */
        syncSubmissionInputStates();

        const errors = validateForm();

        if (errors.length) {
            event.preventDefault();

            saveDraft();

            let message = 'Please correct the following:\\n\\n';

            errors.slice(0, 25).forEach(function (error) {
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
         * This page saves the current asset checklist only.
         * Complete SAF submission is handled from inspections.php.
         */
        try {
            const uploaded = await uploadAllEvidenceFiles();

            if (!uploaded) {
                return false;
            }

            /*
             * Final POST contains no individual evidence files.
             * This prevents PHP max_file_uploads from truncating
             * large PAT checklists.
             */
            form.querySelectorAll('input[type="file"]').forEach(function (input) {
                input.disabled = true;
            });

            const hidden = document.getElementById('uploadedEvidenceJson');

            if (hidden) {
                hidden.value = JSON.stringify(uploadedEvidence);
            }

            setDraftStatus('✓ Evidence uploaded • saving checklist…', 'saved');

            form.submit();
        } catch (error) {
            console.error(error);

            setDraftStatus(
                'Evidence upload failed',
                'error'
            );

            alert(
                'Evidence upload failed:\n\n' +
                (error?.message || 'Unknown upload error.') +
                '\n\nPlease try the Save again.'
            );

            /*
             * Re-enable file inputs so the Inspector can retry without
             * losing the selected files.
             */
            form.querySelectorAll('input[type="file"]').forEach(function (input) {
                input.disabled = false;
            });

            return false;
        }
    });

    const ropeCondition = document.getElementById('ropePhysicalCondition');
    const ropeDate = document.getElementById('ropeReplacementDate');
    const ropeResult = document.getElementById('ropeAcceptanceResult');
    const ropeStatus = document.getElementById('ropeAssessmentStatus');
    const ropeJustificationWrap = document.getElementById('ropeTechnicalJustificationWrap');
    const ropeJustification = document.getElementById('ropeTechnicalJustification');

    function calculateRopeAgeYears(value){
        if(!value) return null;
        const d=new Date(value+'T00:00:00'); const t=new Date(); t.setHours(0,0,0,0);
        if(Number.isNaN(d.getTime())||d>t) return null;
        let y=t.getFullYear()-d.getFullYear(); const m=t.getMonth()-d.getMonth(); const day=t.getDate()-d.getDate();
        if(m<0||(m===0&&day<0)) y--; return y;
    }
    function updateRopeAssessment(){
        if(!ropeCondition||!ropeDate||!ropeResult||!ropeStatus) return;
        const c=ropeCondition.value, age=calculateRopeAgeYears(ropeDate.value);
        ropeResult.value='';

        const ropeEvidenceWrap = document.getElementById('ropeEvidenceWrap');
        if (ropeEvidenceWrap) ropeEvidenceWrap.style.display='none';
        if(ropeJustificationWrap) ropeJustificationWrap.style.display='none';

        if(!c||!ropeDate.value){
            const ropeSavedBox = form.querySelector(
                '.saved-evidence-box[data-evidence-scope="rope"]'
            );
            if (ropeSavedBox) {
                renderSavedEvidenceBox(
                    ropeSavedBox,
                    {photo:'', video:''}
                );
            }
            ropeStatus.textContent='Select condition and replacement date to calculate the result.';
            return;
        }

        if(age===null){
            const ropeSavedBox = form.querySelector(
                '.saved-evidence-box[data-evidence-scope="rope"]'
            );
            if (ropeSavedBox) {
                renderSavedEvidenceBox(
                    ropeSavedBox,
                    {photo:'', video:''}
                );
            }
            ropeStatus.textContent='Invalid replacement date. Future dates are not allowed.';
            return;
        }

        const result=c==='Not Healthy'?'NC':(age<=3?'C':'CA');
        ropeResult.value=result;

        const ropeSavedBox = form.querySelector(
            '.saved-evidence-box[data-evidence-scope="rope"]'
        );
        if (ropeSavedBox) {
            renderSavedEvidenceBox(
                ropeSavedBox,
                getSavedEvidence('rope', 'ROPE-SUP-01', '')
            );
        }

        /* Rope Photo / Video is shown ONLY for NC or CA. */
        if ((result === 'NC' || result === 'CA') && ropeEvidenceWrap) {
            ropeEvidenceWrap.style.display='';
        }

        if(result==='CA'){
            ropeStatus.textContent='Rope age: '+age+' year(s). Result: CA — Conditional Acceptance. Technical justification is REQUIRED.';
            if(ropeJustificationWrap) ropeJustificationWrap.style.display='';
        } else if(result==='NC') {
            ropeStatus.textContent='Rope age: '+age+' year(s). Result: NC — Non-Complied.';
        } else {
            ropeStatus.textContent='Rope age: '+age+' year(s). Result: C — Complied.';
        }
    }
    if(ropeCondition) ropeCondition.addEventListener('change',function(){updateRopeAssessment();scheduleDraftSave();});
    if(ropeDate) ropeDate.addEventListener('change',function(){updateRopeAssessment();scheduleDraftSave();});
    if(ropeJustification) ropeJustification.addEventListener('input',scheduleDraftSave);
    updateRopeAssessment();

    const resetButton = document.getElementById('resetChecklist');

    if (resetButton) {
        resetButton.addEventListener('click', function (event) {
            if (isFinalSubmitted) {
                event.preventDefault();
                return;
            }
            if (!window.confirm(
                'Reset the checklist fields on this page? Unsaved data will be cleared.'
            )) {
                event.preventDefault();
                return;
            }

            try {
                localStorage.removeItem(draftKey);
            } catch (e) {}

            window.setTimeout(function () {
                form.querySelectorAll('.dynamic-required').forEach(function (el) {
                    el.classList.remove('show');
                });

                form.querySelectorAll('.result-evidence').forEach(function (el) {
                    el.classList.remove('show');
                });

                form.querySelectorAll('.floor-result-evidence').forEach(function (el) {
                    el.style.display = 'none';
                });

                updateSummary();
                setDraftStatus('Draft cleared', 'saving');
            }, 50);
        });
    }

    window.addEventListener('beforeunload', function () {
        if (!restoring && !isFinalSubmitted) saveDraft();
    });

    if (!isFinalSubmitted) {
        restoreDraft();
    } else if (draftStatus) {
        draftStatus.textContent = 'Final submitted — checklist is view-only';
        draftStatus.className = 'draft-status saved';
        const ropeSavedBox = form.querySelector(
            '.saved-evidence-box[data-evidence-scope="rope"]'
        );
        if (ropeSavedBox) {
            renderSavedEvidenceBox(
                ropeSavedBox,
                getSavedEvidence('rope', 'ROPE-SUP-01', '')
            );
        }

    }

    form.querySelectorAll('input[name^="result["]:checked').forEach(function (input) {
        updateFixedCheckpoint(input.dataset.ref);
    });

    form.querySelectorAll('.floor-check-row').forEach(updateFloorRow);

    if (isFinalSubmitted) {
        form.classList.add('final-submitted');

        /*
         * Server-rendered fieldset is the primary lock.
         * This second layer protects against later JavaScript
         * attempts to re-enable editable controls.
         */
        form.querySelectorAll(
            'input:not([type="hidden"]), textarea, select, button[type="button"]'
        ).forEach(function (el) {
            el.disabled = true;
        });

        if (saveAssetNextButton) {
            saveAssetNextButton.remove();
        }

        const resetBtn = document.getElementById('resetChecklist');
        if (resetBtn) {
            resetBtn.remove();
        }
    }

    syncSubmissionInputStates();
    updateSummary();
})();
</script>

<?php include('../includes/footer.php'); ?>