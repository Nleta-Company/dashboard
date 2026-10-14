<?php
/* =========================================================
   NLETA OWNER — SAF CONTRACTS
   ---------------------------------------------------------
   Asset-wise SAF display:
   - One SAF Contract = One Asset
   - Each SAF has its own Location
   - Each SAF has its own Proposed Inspection Date
   - Owner accepts/views each SAF independently
   ========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/nleta-mailer.php');

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

$ownerId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);

if ($ownerId <= 0) {
    header('Location: ./dashboard.php');
    exit;
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatSafDate($value): string
{
    if (!$value) {
        return 'Not scheduled';
    }

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('Asia/Kolkata'));
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d-m-Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$safs = [];

/* =========================================================
   OWNER — REQUEST PROPOSED INSPECTION DATE CHANGE
   ---------------------------------------------------------
   Owner cannot directly overwrite the SAF date.
   Owner submits a requested date + reason.
   Admin reviews it and uses Admin SAF View → Change
   Inspection Date to make the actual database change.
========================================================= */

try {
    $conn->query("
        CREATE TABLE IF NOT EXISTS saf_date_change_requests (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            saf_id INT UNSIGNED NOT NULL,
            owner_id INT NOT NULL,
            current_date DATE NULL,
            requested_date DATE NOT NULL,
            reason VARCHAR(1000) NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Pending Admin Review',
            requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_action_at DATETIME NULL,
            admin_note VARCHAR(1000) NULL,
            PRIMARY KEY (id),
            KEY idx_saf_date_req_saf (saf_id),
            KEY idx_saf_date_req_owner (owner_id),
            KEY idx_saf_date_req_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    error_log('NLETA SAF Date Request Table Error: ' . $e->getMessage());
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['request_date_change'])
) {
    $safIdRequest = (int)($_POST['saf_id'] ?? 0);
    $requestedDate = trim((string)($_POST['requested_inspection_date'] ?? ''));
    $reason = trim((string)($_POST['date_change_reason'] ?? ''));

    if (
        function_exists('validateCSRFToken')
        && !validateCSRFToken($_POST['csrf_token'] ?? '')
    ) {
        header('Location: saf-list.php?error=' . urlencode('Invalid security token. Please refresh and try again.'));
        exit;
    }

    try {
        if ($safIdRequest <= 0) {
            throw new Exception('Invalid SAF selected.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate)) {
            throw new Exception('Please select a valid requested inspection date.');
        }

        $dateObject = DateTime::createFromFormat(
            'Y-m-d',
            $requestedDate,
            new DateTimeZone('Asia/Kolkata')
        );
        $dateErrors = DateTime::getLastErrors();

        if (
            !$dateObject
            || (
                is_array($dateErrors)
                && (
                    ($dateErrors['warning_count'] ?? 0) > 0
                    || ($dateErrors['error_count'] ?? 0) > 0
                )
            )
            || $dateObject->format('Y-m-d') !== $requestedDate
        ) {
            throw new Exception('Invalid requested inspection date.');
        }

        $todayIst = new DateTime('today', new DateTimeZone('Asia/Kolkata'));
        if ($dateObject < $todayIst) {
            throw new Exception('Requested inspection date cannot be in the past.');
        }

        if ($reason === '') {
            throw new Exception('Please enter the reason for requesting the date change.');
        }

        if (mb_strlen($reason) > 1000) {
            throw new Exception('Reason is too long. Maximum 1000 characters.');
        }

        $safStmt = $conn->prepare("
            SELECT
                s.id,
                s.owner_id,
                s.psga_no,
                s.saf_no,
                s.service_name,
                s.proposed_inspection_date,
                s.status,
                a.display_asset_id,
                a.asset_name,
                a.location,
                u.name AS owner_name,
                u.email AS owner_email
            FROM saf_contracts s
            LEFT JOIN assets a ON a.id = s.asset_id
            LEFT JOIN users u ON u.id = s.owner_id
            WHERE s.id = ?
              AND s.owner_id = ?
            LIMIT 1
        ");

        if (!$safStmt) {
            throw new Exception('Unable to load SAF.');
        }

        $safStmt->bind_param('ii', $safIdRequest, $ownerId);
        $safStmt->execute();
        $requestSaf = $safStmt->get_result()->fetch_assoc();
        $safStmt->close();

        if (!$requestSaf) {
            throw new Exception('SAF not found.');
        }

        if (!in_array(
            trim((string)$requestSaf['status']),
            ['Pending Owner Acceptance', 'Accepted'],
            true
        )) {
            throw new Exception('Date change request is not available for this SAF at the current stage.');
        }

        $currentDateRaw = trim((string)($requestSaf['proposed_inspection_date'] ?? ''));

        if ($currentDateRaw === $requestedDate) {
            throw new Exception('Requested date is the same as the current Proposed Inspection Date.');
        }

        /* Do not allow multiple unresolved requests for the same SAF. */
        $pendingStmt = $conn->prepare("
            SELECT id
            FROM saf_date_change_requests
            WHERE saf_id = ?
              AND owner_id = ?
              AND status = 'Pending Admin Review'
            ORDER BY id DESC
            LIMIT 1
        ");

        if ($pendingStmt) {
            $pendingStmt->bind_param('ii', $safIdRequest, $ownerId);
            $pendingStmt->execute();
            $pendingExists = $pendingStmt->get_result()->fetch_assoc();
            $pendingStmt->close();

            if ($pendingExists) {
                throw new Exception('A date change request for this SAF is already pending Admin review.');
            }
        }

        $insertReq = $conn->prepare("
            INSERT INTO saf_date_change_requests
                (
                    saf_id,
                    owner_id,
                    current_inspection_date,
                    requested_date,
                    reason,
                    status
                )
            VALUES (?, ?, ?, ?, ?, 'Pending Admin Review')
        ");

        if (!$insertReq) {
            throw new Exception('Unable to create date change request: ' . $conn->error);
        }

        $currentDateDb = $currentDateRaw !== '' ? $currentDateRaw : null;

        $insertReq->bind_param(
            'iisss',
            $safIdRequest,
            $ownerId,
            $currentDateDb,
            $requestedDate,
            $reason
        );

        if (!$insertReq->execute()) {
            throw new Exception('Unable to save date change request: ' . $insertReq->error);
        }

        $requestId = (int)$insertReq->insert_id;
        $insertReq->close();

        /* Notify Admin. Email failure must NOT undo the saved request. */
        if (function_exists('sendNletaSafDateChangeRequestAdminEmail')) {
            try {
                sendNletaSafDateChangeRequestAdminEmail(
                    $requestId,
                    $safIdRequest,
                    (string)($requestSaf['saf_no'] ?? ''),
                    (string)($requestSaf['psga_no'] ?? ''),
                    (string)($requestSaf['owner_name'] ?? ''),
                    (string)($requestSaf['owner_email'] ?? ''),
                    (string)($requestSaf['service_name'] ?? ''),
                    (string)($requestSaf['display_asset_id'] ?? ''),
                    (string)($requestSaf['asset_name'] ?? ''),
                    (string)($requestSaf['location'] ?? ''),
                    $currentDateRaw !== '' ? formatSafDate($currentDateRaw) : 'Not scheduled',
                    formatSafDate($requestedDate),
                    $reason,
                    date('d-m-Y h:i A')
                );
            } catch (Throwable $mailError) {
                error_log('NLETA SAF Date Change Request Email Error: ' . $mailError->getMessage());
            }
        }

        header(
            'Location: saf-list.php?date_request_sent=1&success='
            . urlencode(
                'Date change request submitted to NLETA Admin. The Proposed Inspection Date will change only after Admin approval.'
            )
        );
        exit;

    } catch (Throwable $e) {
        header(
            'Location: saf-list.php?error='
            . urlencode($e->getMessage())
        );
        exit;
    }
}

/* =========================================================
   LOAD OWNER SAFs
========================================================= */

$stmt = $conn->prepare("

    SELECT
        s.id,
        s.psga_no,
        s.saf_no,
        s.service_code,
        s.service_name,
        s.saf_type,
        s.status,
        s.activation_date,
        s.generated_at,
        s.forwarded_to_owner_at,
        s.owner_accepted_at,
        s.proposed_inspection_date,

        a.display_asset_id,
        a.asset_name,
        a.location

    FROM saf_contracts s

    LEFT JOIN assets a
        ON a.id = s.asset_id

    WHERE
        s.owner_id = ?
        AND s.status IN (
            'Pending Owner Acceptance',
            'Accepted'
        )

    ORDER BY
        CASE
            WHEN s.status = 'Pending Owner Acceptance' THEN 1
            ELSE 2
        END,
        s.proposed_inspection_date ASC,
        s.id DESC
");

if (!$stmt) {
    die('Unable to load SAF : ' . $conn->error);
}

$stmt->bind_param('i', $ownerId);

if (!$stmt->execute()) {
    die('Unable to load SAF : ' . $stmt->error);
}

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $safs[] = $row;
}

$stmt->close();

$pendingCount = 0;
$acceptedCount = 0;

foreach ($safs as $row) {
    if ($row['status'] === 'Pending Owner Acceptance') {
        $pendingCount++;
    }

    if ($row['status'] === 'Accepted') {
        $acceptedCount++;
    }
}

$successMessage = trim((string)($_GET['success'] ?? ''));
$errorMessage = trim((string)($_GET['error'] ?? ''));

include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<style>
.owner-saf-page{
    min-height:calc(100vh - 65px);
    padding:24px;
    background:#f4f6f9;
}

.owner-saf-header,
.owner-saf-card,
.owner-saf-stat{
    background:#fff;
    border:1px solid #e3e6ea;
    border-radius:14px;
    box-shadow:0 3px 14px rgba(0,0,0,.06);
}

.owner-saf-header{
    padding:22px 24px;
    margin-bottom:18px;
}

.owner-saf-header h2{
    margin:0;
    font-size:24px;
    font-weight:700;
    color:#172033;
}

.owner-saf-header p{
    margin:6px 0 0;
    color:#6c757d;
    font-size:14px;
}

.owner-saf-stat{
    padding:18px 20px;
    height:100%;
}

.owner-saf-stat-label{
    font-size:12px;
    color:#6c757d;
    text-transform:uppercase;
    letter-spacing:.35px;
    font-weight:600;
}

.owner-saf-stat-value{
    font-size:28px;
    line-height:1.2;
    font-weight:700;
    margin-top:7px;
}

.owner-saf-card{
    overflow:hidden;
}

.owner-saf-table-wrap{
    width:100%;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
}

.owner-saf-table{
    width:1250px;
    min-width:1250px;
    border-collapse:collapse;
    margin:0;
}

.owner-saf-table th{
    background:#f8f9fa;
    padding:13px 14px;
    font-size:11px;
    text-transform:uppercase;
    letter-spacing:.4px;
    color:#596579;
    border-bottom:1px solid #dee2e6;
    white-space:nowrap;
    vertical-align:middle;
}

.owner-saf-table td{
    padding:15px 14px;
    border-bottom:1px solid #edf0f2;
    vertical-align:middle;
    color:#263447;
    font-size:13px;
    line-height:1.45;
}

.owner-saf-table tbody tr:last-child td{
    border-bottom:0;
}

.owner-saf-table tbody tr:hover td{
    background:#fbfcfe;
}

.saf-number{
    font-weight:700;
    color:#0d6efd;
    white-space:nowrap;
}

.asset-id{
    font-weight:700;
    color:#172033;
}

.location-box{
    display:inline-flex;
    align-items:flex-start;
    gap:5px;
    color:#344054;
    font-weight:600;
}

.inspection-date{
    display:inline-flex;
    align-items:center;
    gap:5px;
    font-weight:700;
    color:#344054;
    white-space:nowrap;
}

.not-scheduled{
    color:#98a2b3;
    font-weight:500;
}

.saf-status{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    padding:6px 10px;
    border-radius:18px;
    font-size:11px;
    font-weight:700;
    white-space:nowrap;
}

.status-pending{
    background:#fff3cd;
    color:#856404;
}

.status-accepted{
    background:#d1e7dd;
    color:#0f5132;
}

.asset-note{
    margin-top:3px;
    color:#6c757d;
    font-size:11px;
}

.owner-saf-table .btn{
    min-width:105px;
    font-weight:600;
}

/* =========================================================
   RESPONSIVE SAF PAGE
   ---------------------------------------------------------
   Desktop:
   - Existing layout remains unchanged.

   Tablet + Mobile:
   - Summary cards become 2 columns.
   - Header content wraps cleanly.
   - SAF table remains horizontally scrollable instead
     of breaking the page width.
   - Action buttons remain usable.
   - Modal fits small screens.
========================================================= */

.owner-saf-summary-grid > [class*="col-"]{
    min-width:0;
}

.owner-saf-table-wrap{
    max-width:100%;
}

.owner-saf-table td,
.owner-saf-table th{
    box-sizing:border-box;
}

@media(max-width:1199.98px){

    .owner-saf-page{
        padding:16px;
    }

    .owner-saf-header{
        padding:20px;
    }

    .owner-saf-summary-grid > [class*="col-"]{
        flex:0 0 50%;
        max-width:50%;
    }

    .owner-saf-table-wrap{
        overflow-x:auto;
        overflow-y:hidden;
    }
}

@media(max-width:767.98px){

    .owner-saf-page{
        padding:10px;
    }

    .owner-saf-header{
        padding:16px;
        margin-bottom:14px;
        border-radius:12px;
    }

    .owner-saf-header h2{
        font-size:19px;
        line-height:1.3;
    }

    .owner-saf-header p{
        font-size:12px;
        line-height:1.5;
    }

    .owner-saf-header .badge{
        font-size:10px;
        padding:7px 9px !important;
    }

    .owner-saf-summary-grid{
        --bs-gutter-x:.65rem;
        --bs-gutter-y:.65rem;
    }

    .owner-saf-summary-grid > [class*="col-"]{
        flex:0 0 50%;
        max-width:50%;
    }

    .owner-saf-stat{
        padding:13px 14px;
        min-height:82px;
        border-radius:11px;
    }

    .owner-saf-stat-label{
        font-size:9px;
        letter-spacing:.2px;
        line-height:1.25;
    }

    .owner-saf-stat-value{
        font-size:22px;
        margin-top:5px;
    }

    .owner-saf-card{
        border-radius:11px;
    }

    .owner-saf-table{
        min-width:1050px;
        width:1050px;
    }

    .owner-saf-table th{
        padding:11px 10px;
        font-size:9px;
    }

    .owner-saf-table td{
        padding:12px 10px;
        font-size:11px;
    }

    .owner-saf-table .btn{
        min-width:100px;
        font-size:10px;
        padding:6px 8px;
    }

    .saf-number{
        font-size:11px;
    }

    .asset-id{
        font-size:11px;
    }

    .asset-note{
        font-size:9px;
    }

    .location-box{
        font-size:10px;
        line-height:1.35;
        white-space:normal;
    }

    .inspection-date{
        font-size:10px;
    }

    .saf-status{
        font-size:9px;
        padding:5px 8px;
    }

    .owner-saf-table-wrap{
        border-radius:11px;
        scrollbar-width:thin;
    }

    .modal-dialog{
        margin:10px;
    }

    .modal-content{
        border-radius:12px;
    }

    .modal-header,
    .modal-body,
    .modal-footer{
        padding:13px;
    }

    .modal-title{
        font-size:16px;
    }

    .modal-footer{
        gap:7px;
    }

    .modal-footer .btn{
        flex:1 1 auto;
        min-width:110px;
    }
}

@media(max-width:380px){

    .owner-saf-page{
        padding:8px;
    }

    .owner-saf-header{
        padding:13px;
    }

    .owner-saf-header h2{
        font-size:17px;
    }

    .owner-saf-stat{
        padding:11px 12px;
        min-height:76px;
    }

    .owner-saf-stat-value{
        font-size:20px;
    }

    .owner-saf-table{
        min-width:1000px;
        width:1000px;
    }

    .owner-saf-table th,
    .owner-saf-table td{
        padding-left:8px;
        padding-right:8px;
    }
}
</style>

<div class="content">

<div class="owner-saf-page">

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i>
            <?= h($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i>
            <?= h($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="owner-saf-header">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>
                <h2>
                    <i class="bi bi-file-earmark-check me-2"></i>
                    SAF - Service Activation Form
                </h2>

                <p>
                    Each SAF is issued separately for an individual asset.
                    Location and Proposed Inspection Date are specific to that SAF.
                </p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 owner-saf-summary-grid">

        <div class="col-md-4">
            <div class="owner-saf-stat">
                <div class="owner-saf-stat-label">Total SAF</div>
                <div class="owner-saf-stat-value">
                    <?= count($safs) ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="owner-saf-stat">
                <div class="owner-saf-stat-label">Pending Acceptance</div>
                <div class="owner-saf-stat-value text-warning">
                    <?= $pendingCount ?>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="owner-saf-stat">
                <div class="owner-saf-stat-label">Accepted</div>
                <div class="owner-saf-stat-value text-success">
                    <?= $acceptedCount ?>
                </div>
            </div>
        </div>

    </div>

    <div class="owner-saf-card">

        <div class="owner-saf-table-wrap">

            <table class="owner-saf-table">

                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Service</th>
                        <th>Asset</th>
                        <th>Location</th>
                        <th>PSGA / Contract No.</th>
                        <th>SAF No.</th>
                        <th>Proposed Inspection Date</th>
                        <th>Status</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (!$safs): ?>

                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-file-earmark-x" style="font-size:42px;"></i>

                            <div class="mt-2">
                                No SAF is currently available.
                            </div>
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($safs as $saf): ?>

                        <tr>

                            <!-- ACTION -->

                            <td>
                                <div class="d-flex flex-column gap-2">
                                    <a
                                        href="./saf-view.php?id=<?= (int)$saf['id'] ?>"
                                        class="btn btn-sm btn-primary"
                                    >
                                        <i class="bi bi-eye me-1"></i>
                                        View SAF
                                    </a>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-warning"
                                        onclick="openDateRequestModal(
                                            <?= (int)$saf['id'] ?>,
                                            '<?= h($saf['saf_no']) ?>',
                                            '<?= h($saf['proposed_inspection_date'] ?? '') ?>'
                                        )"
                                    >
                                        <i class="bi bi-calendar2-week me-1"></i>
                                        Request Date Change
                                    </button>
                                </div>
                            </td>


                            <!-- SERVICE -->
                            <td>
                                <strong>
                                    <?= h(
                                        $saf['service_name']
                                        ?: $saf['service_code']
                                        ?: 'PAT'
                                    ) ?>
                                </strong>
                            </td>

                            <!-- ASSET -->
                            <td>
                                <div class="asset-id">
                                    <?= h(
                                        $saf['display_asset_id']
                                        ?: $saf['asset_name']
                                        ?: ('Asset #' . $saf['id'])
                                    ) ?>
                                </div>

                                <?php if (!empty($saf['asset_name'])): ?>
                                    <div class="asset-note">
                                        <?= h($saf['asset_name']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <!-- LOCATION -->
                            <td>
                                <?php if (!empty($saf['location'])): ?>
                                    <span class="location-box">
                                        <i class="bi bi-geo-alt"></i>
                                        <?= h($saf['location']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Not specified</span>
                                <?php endif; ?>
                            </td>

                            <!-- PSGA -->
                            <td>
                                <span class="saf-number">
                                    <?= h($saf['psga_no']) ?>
                                </span>
                            </td>

                            <!-- SAF -->
                            <td>
                                <span class="saf-number">
                                    <?= h($saf['saf_no']) ?>
                                </span>
                            </td>

                            <!-- DATE -->
                            <td>
                                <?php if (!empty($saf['proposed_inspection_date'])): ?>
                                    <span class="inspection-date">
                                        <i class="bi bi-calendar-event"></i>
                                        <?= h(formatSafDate($saf['proposed_inspection_date'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="not-scheduled">
                                        Not scheduled
                                    </span>
                                <?php endif; ?>
                            </td>

                            <!-- STATUS -->
                            <td>
                                <?php if (
                                    $saf['status'] ===
                                    'Pending Owner Acceptance'
                                ): ?>

                                    <span class="saf-status status-pending">
                                        <i class="bi bi-clock me-1"></i>
                                        Pending Acceptance
                                    </span>

                                <?php else: ?>

                                    <span class="saf-status status-accepted">
                                        <i class="bi bi-check-circle me-1"></i>
                                        Accepted
                                    </span>

                                <?php endif; ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</div>


<!-- =========================================================
     OWNER — REQUEST INSPECTION DATE CHANGE MODAL
========================================================= -->
<div class="modal fade" id="dateChangeRequestModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-primary text-white">
                <div>
                    <h5 class="modal-title mb-1">
                        <i class="bi bi-calendar2-week me-2"></i>
                        Request Inspection Date Change
                    </h5>
                    <small id="dateRequestSafLabel"></small>
                </div>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>
            </div>

            <form method="POST" id="dateChangeRequestForm">

                <div class="modal-body">

                    <?php if (function_exists('csrfField')): ?>
                        <?= csrfField() ?>
                    <?php else: ?>
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($_SESSION['csrf_token'] ?? '') ?>"
                        >
                    <?php endif; ?>

                    <input type="hidden" name="saf_id" id="dateRequestSafId">

                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        This is a <strong>request</strong>. The actual SAF date will
                        change only after Admin reviews and approves the request.
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            Current Proposed Inspection Date
                        </label>

                        <input
                            type="text"
                            class="form-control bg-light"
                            id="dateRequestCurrentDate"
                            readonly
                        >
                    </div>

                    <div class="mb-3">
                        <label
                            for="requestedInspectionDate"
                            class="form-label fw-bold"
                        >
                            Requested New Inspection Date
                        </label>

                        <input
                            type="date"
                            class="form-control"
                            id="requestedInspectionDate"
                            name="requested_inspection_date"
                            min="<?= date('Y-m-d') ?>"
                            required
                        >
                    </div>

                    <div class="mb-0">
                        <label
                            for="dateChangeReason"
                            class="form-label fw-bold"
                        >
                            Reason for Date Change
                        </label>

                        <textarea
                            class="form-control"
                            id="dateChangeReason"
                            name="date_change_reason"
                            rows="4"
                            maxlength="1000"
                            placeholder="Please explain why the inspection date needs to be changed..."
                            required
                        ></textarea>

                        <div class="form-text">
                            Maximum 1000 characters.
                        </div>
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
                        name="request_date_change"
                        value="1"
                        class="btn btn-warning"
                    >
                        <i class="bi bi-send me-1"></i>
                        Send Request to Admin
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>

<script>
function openDateRequestModal(safId, safNo, currentDate) {

    document.getElementById('dateRequestSafId').value = safId;
    document.getElementById('dateRequestSafLabel').textContent =
        'SAF No. ' + safNo;

    document.getElementById('dateRequestCurrentDate').value =
        currentDate || 'Not scheduled';

    document.getElementById('requestedInspectionDate').value = '';
    document.getElementById('dateChangeReason').value = '';

    const modalElement =
        document.getElementById('dateChangeRequestModal');

    const modal =
        bootstrap.Modal.getOrCreateInstance(modalElement);

    modal.show();
}
</script>

<?php include('../includes/footer.php'); ?>