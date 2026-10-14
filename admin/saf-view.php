<?php
/* =========================================================
   NLETA ADMIN — SAF CONTRACT VIEW
   ---------------------------------------------------------
   COMPLETE SAF REVIEW / PRINT VIEW

   SUPPORTED SERVICES:
       PAT
       SAT
       SCI
       DGS / DG

   WORKFLOW:
       PSGA Confirmed
            ↓
       SAF Generated
            ↓
       Pending Admin Review
            ↓
       Admin selects Proposed Inspection Date
       (PAT / SAT / SCI only)
            ↓
       Send to Owner
            ↓
       Pending Owner Acceptance
            ↓
       Owner accepts
            ↓
       Accepted

   IMPORTANT:
   - PSGA is never changed or deleted here.
   - DGS is a governance activation service and does NOT
     require a Proposed Inspection Date.
   - PAT / SAT / SCI use their existing approved templates.
   - DGS uses saf-dgs.php.
   - The document is allowed to flow over multiple A4 pages.
   - No document content is removed or compressed just to force
     everything onto one page.
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_once __DIR__ . '/../config/security.php';

$mailerFile = __DIR__ . '/../config/nleta-mailer.php';
if (file_exists($mailerFile)) {
    require_once $mailerFile;
}

require_role('admin');

date_default_timezone_set('Asia/Kolkata');

$safId = (int)($_GET['id'] ?? $_POST['saf_id'] ?? 0);

if ($safId <= 0) {
    header('Location: saf-contracts.php');
    exit;
}

/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function safDate($value): string
{
    if (empty($value)) {
        return '____ / ____ / ______';
    }

    try {
        return (new DateTime(
            (string)$value,
            new DateTimeZone('Asia/Kolkata')
        ))->format('d / m / Y');
    } catch (Throwable $e) {
        return '____ / ____ / ______';
    }
}

/* =========================================================
   LOAD SAF
========================================================= */

$stmt = $conn->prepare("
    SELECT
        s.*,
        u.name AS owner_name,
        u.email AS owner_email,
        a.display_asset_id,
        a.asset_name,
        a.location
    FROM saf_contracts s
    LEFT JOIN users u
        ON u.id = s.owner_id
    LEFT JOIN assets a
        ON a.id = s.asset_id
    WHERE s.id = ?
    LIMIT 1
");

if (!$stmt) {
    die('Unable to prepare SAF query: ' . h($conn->error));
}

$stmt->bind_param('i', $safId);

if (!$stmt->execute()) {
    die('Unable to load SAF: ' . h($stmt->error));
}

$saf = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$saf) {
    die('SAF Contract not found.');
}

/* =========================================================
   SERVICE TYPE
========================================================= */

$serviceCode = strtoupper(
    trim((string)($saf['service_code'] ?? ''))
);

if ($serviceCode === '') {
    $serviceCode = strtoupper(
        trim((string)($saf['saf_type'] ?? ''))
    );
}

$serviceName = trim(
    (string)($saf['service_name'] ?? '')
);

if ($serviceName === '') {
    $serviceName = $serviceCode !== ''
        ? $serviceCode
        : 'Selected Service';
}

$isDgsSaf = in_array(
    $serviceCode,
    ['DG', 'DGS', 'DIGITAL_GOVERNANCE'],
    true
);

/* =========================================================
   DOCUMENT VARIABLES
========================================================= */

$psgaNo = trim((string)($saf['psga_no'] ?? ''));

if ($psgaNo === '') {
    $psgaNo = '____________________________';
}

$safNo = trim((string)($saf['saf_no'] ?? ''));

if ($safNo === '') {
    $safNo = '______________________';
}

$activationDate = safDate(
    $saf['activation_date'] ?? null
);

$inspectionDate = safDate(
    $saf['proposed_inspection_date'] ?? null
);

$assetUniqueId = trim(
    (string)($saf['display_asset_id'] ?? '')
);

if ($assetUniqueId === '') {
    $assetUniqueId = trim(
        (string)($saf['asset_id'] ?? '')
    );
}

if ($assetUniqueId === '') {
    $assetUniqueId = '________________________';
}

$checklist = trim(
    (string)($saf['checklist_reference'] ?? '')
);

if ($checklist === '') {
    $checklist = '__________________________';
}

/* =========================================================
   FORWARD SAF TO OWNER
   ---------------------------------------------------------
   DGS:
       No Proposed Inspection Date.

   PAT / SAT / SCI:
       Proposed Inspection Date is mandatory.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['forward_saf'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Invalid security token. Please refresh the page and try again.')
        );
        exit;
    }

    $postedSafId = (int)($_POST['saf_id'] ?? 0);

    if ($postedSafId !== $safId) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Invalid SAF selected.')
        );
        exit;
    }

    $proposedInspectionDate = null;

    if (!$isDgsSaf) {
        $proposedInspectionDate = trim(
            (string)($_POST['proposed_inspection_date'] ?? '')
        );

        if (!preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $proposedInspectionDate
        )) {
            header(
                'Location: saf-view.php?id=' .
                $safId .
                '&error=' .
                urlencode('Please select a valid Proposed Inspection Date.')
            );
            exit;
        }

        $dateObject = DateTime::createFromFormat(
            'Y-m-d',
            $proposedInspectionDate,
            new DateTimeZone('Asia/Kolkata')
        );

        $dateErrors = DateTime::getLastErrors();

        if (
            !$dateObject
            ||
            (
                is_array($dateErrors)
                &&
                (
                    ($dateErrors['warning_count'] ?? 0) > 0
                    ||
                    ($dateErrors['error_count'] ?? 0) > 0
                )
            )
            ||
            $dateObject->format('Y-m-d') !== $proposedInspectionDate
        ) {
            header(
                'Location: saf-view.php?id=' .
                $safId .
                '&error=' .
                urlencode('Invalid Proposed Inspection Date.')
            );
            exit;
        }

        $todayIst = new DateTime(
            'today',
            new DateTimeZone('Asia/Kolkata')
        );

        if ($dateObject < $todayIst) {
            header(
                'Location: saf-view.php?id=' .
                $safId .
                '&error=' .
                urlencode('Proposed Inspection Date cannot be in the past.')
            );
            exit;
        }
    }

    $conn->begin_transaction();

    try {
        $check = $conn->prepare("
            SELECT
                id,
                saf_no,
                psga_no,
                status
            FROM saf_contracts
            WHERE id = ?
            LIMIT 1
        ");

        if (!$check) {
            throw new Exception(
                'Unable to prepare SAF status check: ' .
                $conn->error
            );
        }

        $check->bind_param('i', $safId);
        $check->execute();

        $current = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$current) {
            throw new Exception('SAF not found.');
        }

        if ($current['status'] !== 'Pending Admin Review') {
            throw new Exception(
                'This SAF is not pending Admin review.'
            );
        }

        $update = $conn->prepare("
            UPDATE saf_contracts
            SET
                status = 'Pending Owner Acceptance',
                proposed_inspection_date = ?,
                admin_reviewed_at = NOW(),
                forwarded_to_owner_at = NOW(),
                updated_at = NOW()
            WHERE
                id = ?
                AND status = 'Pending Admin Review'
        ");

        if (!$update) {
            throw new Exception(
                'Unable to prepare SAF forwarding: ' .
                $conn->error
            );
        }

        $update->bind_param(
            'si',
            $proposedInspectionDate,
            $safId
        );

        if (
            !$update->execute()
            ||
            $update->affected_rows !== 1
        ) {
            throw new Exception(
                'SAF could not be forwarded. Its status may have changed.'
            );
        }

        $update->close();

        $conn->commit();

        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&forwarded=1'
        );
        exit;

    } catch (Throwable $e) {
        $conn->rollback();

        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode($e->getMessage())
        );
        exit;
    }
}

/* =========================================================
   CHANGE PROPOSED INSPECTION DATE
   ---------------------------------------------------------
   Allowed for PAT / SAT / SCI after forwarding or acceptance.

   DGS has no inspection date, so this feature is hidden for DGS.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['change_inspection_date'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Invalid security token. Please refresh the page and try again.')
        );
        exit;
    }

    if ($isDgsSaf) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('DGS does not use a Proposed Inspection Date.')
        );
        exit;
    }

    $postedSafId = (int)($_POST['saf_id'] ?? 0);
    $newDate = trim(
        (string)($_POST['new_proposed_inspection_date'] ?? '')
    );

    if ($postedSafId !== $safId) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Invalid SAF selected.')
        );
        exit;
    }

    if (!preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $newDate
    )) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Please select a valid Proposed Inspection Date.')
        );
        exit;
    }

    $dateObject = DateTime::createFromFormat(
        'Y-m-d',
        $newDate,
        new DateTimeZone('Asia/Kolkata')
    );

    $dateErrors = DateTime::getLastErrors();

    if (
        !$dateObject
        ||
        (
            is_array($dateErrors)
            &&
            (
                ($dateErrors['warning_count'] ?? 0) > 0
                ||
                ($dateErrors['error_count'] ?? 0) > 0
            )
        )
        ||
        $dateObject->format('Y-m-d') !== $newDate
    ) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Invalid Proposed Inspection Date.')
        );
        exit;
    }

    $todayIst = new DateTime(
        'today',
        new DateTimeZone('Asia/Kolkata')
    );

    if ($dateObject < $todayIst) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode('Proposed Inspection Date cannot be in the past.')
        );
        exit;
    }

    try {
        $check = $conn->prepare("
            SELECT
                id,
                saf_no,
                psga_no,
                status,
                proposed_inspection_date
            FROM saf_contracts
            WHERE id = ?
            LIMIT 1
        ");

        if (!$check) {
            throw new Exception(
                'Unable to prepare SAF check: ' .
                $conn->error
            );
        }

        $check->bind_param('i', $safId);
        $check->execute();

        $current = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$current) {
            throw new Exception('SAF not found.');
        }

        if (
            !in_array(
                (string)$current['status'],
                [
                    'Pending Owner Acceptance',
                    'Accepted'
                ],
                true
            )
        ) {
            throw new Exception(
                'Inspection date can only be changed after the SAF has been sent to the Owner.'
            );
        }

        $oldDate = !empty($current['proposed_inspection_date'])
            ? safDate($current['proposed_inspection_date'])
            : 'Not set';

        $update = $conn->prepare("
            UPDATE saf_contracts
            SET
                proposed_inspection_date = ?,
                updated_at = NOW()
            WHERE id = ?
            LIMIT 1
        ");

        if (!$update) {
            throw new Exception(
                'Unable to prepare inspection date update: ' .
                $conn->error
            );
        }

        $update->bind_param(
            'si',
            $newDate,
            $safId
        );

        if (
            !$update->execute()
            ||
            $update->affected_rows !== 1
        ) {
            throw new Exception(
                'Inspection date could not be changed.'
            );
        }

        $update->close();

        $newDateDisplay = safDate($newDate);

        $changedAt = (
            new DateTime(
                'now',
                new DateTimeZone('Asia/Kolkata')
            )
        )->format('d-m-Y h:i A');

        /*
         * Email notification is intentionally non-blocking.
         * The saved date change must remain saved even if mail fails.
         */
        if (
            function_exists(
                'sendNletaSafInspectionDateChangedOwnerEmail'
            )
        ) {
            try {
                sendNletaSafInspectionDateChangedOwnerEmail(
                    $safId,
                    (string)($current['saf_no'] ?? ''),
                    (string)($current['psga_no'] ?? ''),
                    (string)($saf['owner_name'] ?? ''),
                    (string)($saf['owner_email'] ?? ''),
                    $oldDate,
                    $newDateDisplay,
                    $changedAt
                );
            } catch (Throwable $mailError) {
                error_log(
                    'NLETA SAF date-change email failed: ' .
                    $mailError->getMessage()
                );
            }
        }

        $message =
            'Proposed Inspection Date changed from ' .
            $oldDate .
            ' to ' .
            $newDateDisplay .
            '.';

        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&date_changed=1&success=' .
            urlencode($message)
        );
        exit;

    } catch (Throwable $e) {
        header(
            'Location: saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode($e->getMessage())
        );
        exit;
    }
}

/* =========================================================
   PAGE VARIABLES
========================================================= */

$forwarded = (
    isset($_GET['forwarded'])
    &&
    $_GET['forwarded'] === '1'
);

$dateChanged = (
    isset($_GET['date_changed'])
    &&
    $_GET['date_changed'] === '1'
);

$error = trim(
    (string)($_GET['error'] ?? '')
);

$success = trim(
    (string)($_GET['success'] ?? '')
);

$status = trim(
    (string)($saf['status'] ?? '')
);

/* =========================================================
   HTML
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');
?>

<style>
/* =========================================================
   ADMIN SAF REVIEW — SCREEN
========================================================= */

.saf-review-page{
    background:#f3f5f8;
    min-height:calc(100vh - 70px);
    padding:22px;
}

.saf-review-toolbar{
    max-width:1100px;
    margin:0 auto 14px;
    display:flex;
    justify-content:space-between;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}

.saf-review-toolbar .left,
.saf-review-toolbar .right{
    display:flex;
    gap:8px;
    align-items:center;
    flex-wrap:wrap;
}

.saf-status{
    max-width:1100px;
    margin:0 auto 14px;
    background:#fff;
    border:1px solid #ddd;
    border-radius:8px;
    padding:10px 13px;
    font-size:13px;
}

.saf-forward{
    background:#fff3cd;
    color:#664d03;
    font-weight:700;
    padding:4px 8px;
    border-radius:5px;
}

.saf-sent{
    background:#cff4fc;
    color:#055160;
    font-weight:700;
    padding:4px 8px;
    border-radius:5px;
}

.saf-accepted{
    background:#d1e7dd;
    color:#0f5132;
    font-weight:700;
    padding:4px 8px;
    border-radius:5px;
}

/* =========================================================
   DOCUMENT VIEW
   ---------------------------------------------------------
   The document itself is A4 width.
   Height is AUTO so it can naturally continue onto
   page 2, page 3, etc.
========================================================= */

.saf-document-stage{
    width:100%;
    max-width:none;
    margin:0 auto;
}

.saf-paper{
    width:210mm;
    max-width:100%;
    height:auto;
    min-height:297mm;
    margin:0 auto;
    background:#fff;
    box-sizing:border-box;
    padding:13mm 13mm 8mm;
    position:relative;
    color:#111;
    font-family:Arial,Helvetica,sans-serif;
    font-size:14px;
    line-height:1.34;
    overflow:visible;
    box-shadow:0 4px 20px rgba(0,0,0,.12);
}

.saf-paper > *{
    position:relative;
    z-index:1;
}

.saf-paper::before{
    content:"NLETA";
    position:absolute;
    left:50%;
    top:51%;
    transform:translate(-50%,-50%) rotate(-25deg);
    font-size:105px;
    font-weight:800;
    letter-spacing:8px;
    color:rgba(100,100,100,.055);
    z-index:0;
    pointer-events:none;
}

.saf-ref{
    text-align:right;
    font-size:13px;
    margin-bottom:5px;
}

.saf-title{
    text-align:center;
    font-size:24px;
    line-height:1.08;
    font-weight:800;
    margin:0 0 4px;
}

.saf-subtitle{
    text-align:center;
    font-size:15px;
    line-height:1.15;
    font-weight:700;
    margin:5px 0 16px;
}

.saf-heading{
    font-weight:800;
    margin-top:11px;
    margin-bottom:6px;
}

.saf-contract-line{
    font-weight:700;
    white-space:normal;
    margin:4px 0;
}

.saf-contract-line .field{
    display:inline-block;
    font-weight:400;
    border-bottom:1px solid #111;
    min-width:225px;
    max-width:145mm;
    min-height:18px;
    height:auto;
    vertical-align:bottom;
    padding:0 3px;
    white-space:normal;
    overflow-wrap:anywhere;
}

.saf-contract-line .saf-field{
    min-width:185px;
}

.saf-paper p{
    margin:9px 0;
    text-align:justify;
}

.saf-section{
    font-weight:800;
    margin-top:13px;
    margin-bottom:5px;
}

.saf-list{
    margin:5px 0 9px 23px;
    padding:0;
}

.saf-list li{
    margin:3px 0;
    padding-left:2px;
}

.saf-output-label{
    font-weight:800;
}

.saf-signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:65px;
    margin-top:24px;
}

.saf-signature-title{
    font-weight:800;
    margin-bottom:12px;
}

.saf-signature-line{
    margin:8px 0;
}

.saf-bottom{
    text-align:center;
    font-weight:800;
    margin-top:18mm;
    padding-top:12px;
    width:100%;
}

.saf-page-number{
    text-align:center;
    font-size:10px;
    margin-top:6px;
    width:100%;
    display:block;
    clear:both;
}

/* =========================================================
   MOBILE / TABLET
========================================================= */

@media(max-width:1199.98px){
    .saf-review-page{
        padding:16px;
    }

    .saf-paper{
        max-width:100%;
    }
}

@media(max-width:900px){
    .saf-review-page{
        padding:8px;
    }

    .saf-review-toolbar{
        width:100%;
    }

    .saf-paper{
        width:100%;
        min-height:0;
        height:auto;
        padding:25px;
        font-size:11px;
    }

    .saf-title{
        font-size:17px;
    }

    .saf-subtitle{
        font-size:11px;
    }

    .saf-signatures{
        grid-template-columns:1fr;
        row-gap:22px;
    }
}

/* =========================================================
   FINAL PRINT
   ---------------------------------------------------------
   CRITICAL FIX:
   NO 297mm HEIGHT ON html/body/parent.
   NO overflow:hidden.
   NO page-break-inside:avoid ON THE COMPLETE DOCUMENT.
   NO flex column that compresses/cuts the document.

   The browser is therefore free to create as many A4
   sheets as the actual SAF content requires.
========================================================= */

@page{
    size:A4 portrait;
    margin:0;
}

@media print{

    html,
    body{
        width:auto !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        overflow:visible !important;
    }

    header,
    nav,
    aside,
    footer,
    .sidebar,
    .navbar,
    .topbar,
    .header,
    .footer,
    .main-header,
    .admin-header,
    .no-print,
    .saf-review-toolbar,
    .saf-status{
        display:none !important;
    }

    .content,
    .main-content,
    .page-content,
    .dashboard-content,
    .container,
    .container-fluid{
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        position:static !important;
        overflow:visible !important;
    }

    .saf-review-page{
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        overflow:visible !important;
    }

    .saf-document-stage{
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
    }

    /*
     * Parent document container:
     * A4 width, AUTO height.
     */
    .saf-paper{
        width:210mm !important;
        max-width:210mm !important;

        height:auto !important;
        min-height:0 !important;

        margin:0 auto !important;
        padding:13mm 13mm 8mm !important;

        box-sizing:border-box !important;

        border:0 !important;
        border-radius:0 !important;
        box-shadow:none !important;

        overflow:visible !important;

        display:block !important;

        position:relative !important;

        page-break-before:auto !important;
        page-break-after:auto !important;
        page-break-inside:auto !important;

        break-before:auto !important;
        break-after:auto !important;
        break-inside:auto !important;
    }

    /*
     * DGS / PAT / SAT / SCI templates may use their own
     * document wrapper. Never allow the parent page to
     * clip them.
     */
    .saf-dgs-paper,
    .saf-pat-paper,
    .saf-sat-paper,
    .saf-sci-paper{
        height:auto !important;
        max-height:none !important;
        overflow:visible !important;

        page-break-before:auto !important;
        page-break-after:auto !important;
        page-break-inside:auto !important;

        break-before:auto !important;
        break-after:auto !important;
        break-inside:auto !important;
    }

    /*
     * Keep small logical blocks together where possible,
     * but NEVER block the complete document from splitting.
     */
    .saf-dgs-contract,
    .saf-dgs-signatures,
    .saf-signatures{
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }

    .saf-dgs-list,
    .saf-list{
        break-inside:auto !important;
        page-break-inside:auto !important;
    }

    /*
     * DGS footer must remain in normal document flow.
     * It must NOT be pinned to the bottom of a single page.
     */
    .saf-dgs-footer{
        position:static !important;
        left:auto !important;
        right:auto !important;
        bottom:auto !important;
        margin-top:18mm !important;
    }

    .saf-bottom{
        margin-top:18mm !important;
    }

    /*
     * Do not let a parent element create a hidden clipping
     * rectangle around the actual SAF document.
     */
    .saf-review-page *,
    .saf-document-stage *,
    .saf-paper *{
        max-height:none;
    }

    *{
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
    }
}
</style>

<div class="content">

<div class="saf-review-page">

<!-- =====================================================
     TOOLBAR
====================================================== -->

<div class="saf-review-toolbar no-print">

    <div class="left">

        <a
            href="saf-contracts.php"
            class="btn btn-outline-secondary btn-sm"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Back
        </a>

        <strong>
            SAF Contract Review
        </strong>

    </div>

    <div class="right">

        <button
            type="button"
            class="btn btn-outline-dark btn-sm"
            title="For a clean print, turn off Browser Headers and Footers."
            onclick="window.print()"
        >
            <i class="bi bi-printer me-1"></i>
            Print
        </button>

        <?php if (
            !$isDgsSaf
            &&
            in_array(
                $status,
                [
                    'Pending Owner Acceptance',
                    'Accepted'
                ],
                true
            )
        ): ?>

            <button
                type="button"
                class="btn btn-outline-primary btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#changeInspectionDateModal"
            >
                <i class="bi bi-calendar2-range me-1"></i>
                Change Inspection Date
            </button>

        <?php endif; ?>

        <?php if ($status === 'Pending Admin Review'): ?>

            <form
                method="POST"
                class="d-flex align-items-end gap-2 flex-wrap"
                onsubmit="return confirm(
                    'Confirm the SAF details and send this SAF to the Owner?'
                );"
            >

                <?=csrfField()?>

                <input
                    type="hidden"
                    name="saf_id"
                    value="<?= (int)$safId ?>"
                >

                <?php if (!$isDgsSaf): ?>

                    <div>

                        <label
                            for="proposedInspectionDate"
                            class="form-label mb-1 small fw-bold"
                        >
                            Proposed Inspection Date
                        </label>

                        <input
                            type="date"
                            id="proposedInspectionDate"
                            name="proposed_inspection_date"
                            class="form-control form-control-sm"
                            min="<?=date('Y-m-d')?>"
                            value="<?=
                                !empty($saf['proposed_inspection_date'])
                                    ? h($saf['proposed_inspection_date'])
                                    : ''
                            ?>"
                            required
                        >

                    </div>

                <?php endif; ?>

                <button
                    type="submit"
                    name="forward_saf"
                    class="btn btn-success btn-sm"
                >
                    <i class="bi bi-send me-1"></i>

                    <?php if ($isDgsSaf): ?>
                        Review &amp; Send to Owner
                    <?php else: ?>
                        Set Date &amp; Send to Owner
                    <?php endif; ?>

                </button>

            </form>

        <?php endif; ?>

    </div>

</div>

<!-- =====================================================
     MESSAGES
====================================================== -->

<?php if ($forwarded): ?>

    <div class="saf-status saf-sent no-print">
        <i class="bi bi-check-circle-fill me-1"></i>
        SAF has been forwarded to the Owner successfully.
    </div>

<?php elseif ($dateChanged): ?>

    <div class="saf-status alert alert-success no-print">
        <i class="bi bi-check-circle-fill me-1"></i>
        <?=h($success ?: 'Proposed Inspection Date updated successfully.')?>
    </div>

<?php elseif ($success !== ''): ?>

    <div class="saf-status alert alert-success no-print">
        <i class="bi bi-check-circle-fill me-1"></i>
        <?=h($success)?>
    </div>

<?php elseif ($error !== ''): ?>

    <div class="saf-status alert alert-danger no-print">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <?=h($error)?>
    </div>

<?php endif; ?>

<!-- =====================================================
     STATUS
====================================================== -->

<div class="saf-status no-print">

    <strong>Status:</strong>

    <?php if ($status === 'Pending Admin Review'): ?>

        <span class="saf-forward">
            Pending Admin Review
        </span>

    <?php elseif ($status === 'Pending Owner Acceptance'): ?>

        <span class="saf-sent">
            Pending Owner Acceptance
        </span>

    <?php elseif ($status === 'Accepted'): ?>

        <span class="saf-accepted">
            Accepted
        </span>

    <?php else: ?>

        <?=h($status ?: '—')?>

    <?php endif; ?>

    <?php if (
        !$isDgsSaf
        &&
        !empty($saf['proposed_inspection_date'])
    ): ?>

        <span class="text-muted ms-3">
            <i class="bi bi-calendar3 me-1"></i>
            Proposed Inspection Date:
            <strong>
                <?=h(safDate($saf['proposed_inspection_date']))?>
            </strong>
        </span>

    <?php endif; ?>

</div>

<!-- =====================================================
     A4 SAF DOCUMENT
     ---------------------------------------------------------
     SERVICE-SPECIFIC APPROVED TEMPLATE
====================================================== -->

<div class="saf-document-stage">

<?php

$serviceCodeForTemplate = strtoupper(
    trim((string)($saf['service_code'] ?? ''))
);

if ($serviceCodeForTemplate === '') {
    $serviceCodeForTemplate = strtoupper(
        trim((string)($saf['saf_type'] ?? ''))
    );
}

$templateFile = '';

if ($serviceCodeForTemplate === 'PAT') {

    $templateFile =
        __DIR__ .
        '/saf-templates/saf-pat.php';

} elseif ($serviceCodeForTemplate === 'SAT') {

    $templateFile =
        __DIR__ .
        '/saf-templates/saf-sat.php';

} elseif ($serviceCodeForTemplate === 'SCI') {

    $templateFile =
        __DIR__ .
        '/saf-templates/saf-sci.php';

} elseif (
    in_array(
        $serviceCodeForTemplate,
        [
            'DG',
            'DGS',
            'DIGITAL_GOVERNANCE'
        ],
        true
    )
) {

    $templateFile =
        __DIR__ .
        '/saf-templates/saf-dgs.php';

} else {

    die(
        'Unsupported SAF service. Available SAF templates: PAT, SAT, SCI and Digital Governance Support (DGS).'
    );
}

if (!is_file($templateFile)) {

    die(
        'SAF template file is missing: ' .
        h(basename($templateFile))
    );
}

/*
 * Shared approved SAF template receives:
 *     $saf
 *     $conn
 *     $psgaNo
 *     $safNo
 *     $activationDate
 *     $inspectionDate
 *     $assetUniqueId
 *     $checklist
 *
 * The template itself controls the document content.
 */
include $templateFile;

?>

</div>

</div>

<!-- =====================================================
     CHANGE PROPOSED INSPECTION DATE MODAL
====================================================== -->

<?php if (
    !$isDgsSaf
    &&
    in_array(
        $status,
        [
            'Pending Owner Acceptance',
            'Accepted'
        ],
        true
    )
): ?>

<div
    class="modal fade"
    id="changeInspectionDateModal"
    tabindex="-1"
    aria-labelledby="changeInspectionDateModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow-lg">

            <div class="modal-header bg-primary text-white">

                <div>

                    <h5
                        class="modal-title mb-0"
                        id="changeInspectionDateModalLabel"
                    >
                        <i class="bi bi-calendar2-range me-2"></i>
                        Change Proposed Inspection Date
                    </h5>

                    <small class="opacity-75">
                        SAF: <?=h($safNo)?>
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>

            <form
                method="POST"
                onsubmit="return confirm(
                    'Change the Proposed Inspection Date and notify the Owner?'
                );"
            >

                <div class="modal-body">

                    <?=csrfField()?>

                    <input
                        type="hidden"
                        name="saf_id"
                        value="<?= (int)$safId ?>"
                    >

                    <div class="mb-3">

                        <label class="form-label fw-bold">
                            Current Proposed Inspection Date
                        </label>

                        <div class="form-control bg-light">

                            <?=
                                !empty($saf['proposed_inspection_date'])
                                    ? h(
                                        safDate(
                                            $saf['proposed_inspection_date']
                                        )
                                    )
                                    : 'Not set'
                            ?>

                        </div>

                    </div>

                    <div class="mb-3">

                        <label
                            for="newProposedInspectionDate"
                            class="form-label fw-bold"
                        >
                            New Proposed Inspection Date
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="date"
                            id="newProposedInspectionDate"
                            name="new_proposed_inspection_date"
                            class="form-control"
                            min="<?=date('Y-m-d')?>"
                            value="<?=
                                !empty($saf['proposed_inspection_date'])
                                    ? h($saf['proposed_inspection_date'])
                                    : ''
                            ?>"
                            required
                        >

                    </div>

                    <div class="alert alert-warning small mb-0">

                        <i class="bi bi-info-circle me-1"></i>

                        Changing the date does not cancel Owner acceptance,
                        reset the SAF status, or modify Inspector/checklist
                        data. Only the Proposed Inspection Date is changed.

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
                        name="change_inspection_date"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-save me-1"></i>
                        Save New Date
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>

</div>

</div>

<?php include('../includes/footer.php'); ?>
