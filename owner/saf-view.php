<?php
/* =========================================================
   NLETA OWNER — SAF CONTRACT VIEW / CONFIRM ACCEPT
   ---------------------------------------------------------
   Complete Owner SAF View

   WORKFLOW:
   ---------------------------------------------------------
   Admin generates SAF
        ↓
   Pending Admin Review
        ↓
   Admin selects Proposed Inspection Date
        ↓
   Admin sends SAF
        ↓
   Pending Owner Acceptance
        ↓
   Owner clicks Confirm SAF
        ↓
   SAF Accepted
        ↓
   Admin notified by email

   IMPORTANT:
   ---------------------------------------------------------
   Owner confirmation does not change the proposed inspection date.

   The Proposed Inspection Date remains exactly as
   selected by Admin.
========================================================= */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* =========================================================
   PROJECT FILES
========================================================= */

include('../config/db.php');
include('../config/auth.php');

require_once '../config/security.php';


$mailerFile =
    __DIR__ .
    '/../config/nleta-mailer.php';


if (file_exists($mailerFile)) {

    require_once $mailerFile;
}


/* =========================================================
   OWNER ROLE
========================================================= */

require_role('owner');

/* =========================================================
   STATIC ANALYSER / TEMPLATE DEFAULTS
   ---------------------------------------------------------
   Initialise every value used by the HTML template before
   any conditional POST flow. This also prevents
   Intelephense 'Undefined variable' warnings.
========================================================= */
$csrfToken = '';
$psgaNo = '';
$safNo = '';
$activationDate = '';
$inspectionDate = '';
$assetUniqueId = '';
$checklist = '';


/* =========================================================
   TIMEZONE
========================================================= */

date_default_timezone_set('Asia/Kolkata');


$timezone =
    new DateTimeZone(
        'Asia/Kolkata'
    );


/* =========================================================
   OWNER / SAF
========================================================= */

$ownerId =
    (int)(
        $_SESSION['uid']
        ??
        $_SESSION['user_id']
        ??
        0
    );


$safId =
    (int)(
        $_GET['id']
        ??
        $_POST['saf_id']
        ??
        0
    );


if ($ownerId <= 0) {

    header(
        'Location: ./dashboard.php'
    );

    exit;
}


if ($safId <= 0) {

    header(
        'Location: ./saf-list.php'
    );

    exit;
}


/* =========================================================
   HELPERS
========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function safDate($value)
{
    if (empty($value)) {

        return '____ / ____ / ______';
    }


    try {

        return (
            new DateTime(
                $value,
                new DateTimeZone(
                    'Asia/Kolkata'
                )
            )
        )->format(
            'd / m / Y'
        );

    } catch (Throwable $e) {

        return '____ / ____ / ______';
    }
}


/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['csrf_saf'])) {
    $_SESSION['csrf_saf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_saf'];


/* =========================================================
   LOAD SAF
========================================================= */

$stmt =
    $conn->prepare("
        SELECT

            s.*,

            u.name AS owner_name,
            u.email AS owner_email,

            a.display_asset_id,
            a.asset_name

        FROM saf_contracts s

        LEFT JOIN users u
            ON u.id = s.owner_id

        LEFT JOIN assets a
            ON a.id = s.asset_id

        WHERE
            s.id = ?

            AND s.owner_id = ?

        LIMIT 1
    ");


if (!$stmt) {

    die(
        'Unable to prepare SAF query: ' .
        h($conn->error)
    );
}


$stmt->bind_param(
    'ii',
    $safId,
    $ownerId
);


if (
    !$stmt->execute()
) {

    die(
        'Unable to load SAF: ' .
        h($stmt->error)
    );
}


$saf =
    $stmt
        ->get_result()
        ->fetch_assoc();


$stmt->close();


if (!$saf) {

    header(
        'Location: ./saf-list.php'
    );

    exit;
}


/* =========================================================
   OWNER ACCESS CONTROL
   ---------------------------------------------------------
   Owner must NOT see SAF while it is still
   Pending Admin Review.
========================================================= */

$allowedOwnerStatuses = [
    'Pending Owner Acceptance',
    'Accepted'
];


if (
    !in_array(
        trim(
            (string)(
                $saf['status']
                ??
                ''
            )
        ),
        $allowedOwnerStatuses,
        true
    )
) {

    header(
        'Location: ./saf-list.php'
    );

    exit;
}


/* =========================================================
   SAF DATA
========================================================= */

$status =
    trim(
        (string)(
            $saf['status']
            ??
            ''
        )
    );


$ownerName =
    trim(
        (string)(
            $saf['owner_name']
            ??
            'Owner'
        )
    );


$ownerEmail =
    trim(
        (string)(
            $saf['owner_email']
            ??
            ''
        )
    );


$psgaNo =
    !empty(
        $saf['psga_no']
    )
    ? $saf['psga_no']
    : '____________________________';


$safNo =
    !empty(
        $saf['saf_no']
    )
    ? $saf['saf_no']
    : '______________________';


$activationDate =
    safDate(
        $saf['activation_date']
        ??
        null
    );


$inspectionDate =
    safDate(
        $saf['proposed_inspection_date']
        ??
        null
    );


/* =========================================================
   DIGITAL GOVERNANCE SUPPORT DETECTION
   ---------------------------------------------------------
   Existing service catalog may use DG, while newer SAF rows
   may use DGS. Both are treated as the DGS SAF.
========================================================= */

$ownerServiceCodeRaw = strtoupper(
    trim((string)($saf['service_code'] ?? ''))
);

$ownerServiceNameRaw = strtoupper(
    trim((string)($saf['service_name'] ?? ''))
);

$isDgsSaf =
    in_array(
        $ownerServiceCodeRaw,
        ['DG', 'DGS', 'DIGITAL_GOVERNANCE'],
        true
    )
    ||
    strpos($ownerServiceNameRaw, 'DIGITAL GOVERNANCE') !== false
    ||
    strpos($ownerServiceNameRaw, 'GOVERNANCE SUPPORT') !== false;


/* =========================================================
   ASSET REGISTRATION / UNIQUE ID
   ---------------------------------------------------------
   THIS SAF IS ASSET-SPECIFIC.

   Only the asset stored in saf_contracts.asset_id belongs
   to this SAF. Do NOT load all assets from
   service_request_assets/request_id here.

   This prevents SAF-0001 for Asset-A from showing
   Asset-B, Asset-C, etc.
========================================================= */

$assetUniqueId = trim(
    (string)($saf['display_asset_id'] ?? '')
);

/* Legacy fallback if display_asset_id is unavailable. */
if ($assetUniqueId === '') {
    $assetUniqueId = trim(
        (string)($saf['asset_id'] ?? '')
    );
}

if ($assetUniqueId === '') {
    $assetUniqueId = '________________________';
}


/* =========================================================
   SAF-LEVEL REFERENCE
========================================================= */


$safAssetReference =
    !empty(
        $saf['saf_no']
    )
    ? $saf['saf_no']
    : '______________________';


/* =========================================================
   MESSAGES
========================================================= */

$accepted =
    isset(
        $_GET['accepted']
    )
    &&
    $_GET['accepted'] === '1';


$error =
    trim(
        (string)(
            $_GET['error']
            ??
            ''
        )
    );


$success =
    trim(
        (string)(
            $_GET['success']
            ??
            ''
        )
    );


/* =========================================================
   CONFIRM SAF
   ---------------------------------------------------------
   Owner directly confirms the SAF.

   IMPORTANT:
   - Owner confirms directly.
   - Confirmation is protected by CSRF.
   - SAF must still be Pending Owner Acceptance.
   - Proposed Inspection Date is NOT changed.
   - Admin notification email is sent AFTER the DB commit.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['confirm_saf'])
) {

    $postedSafId = (int)($_POST['saf_id'] ?? 0);

    if (!hash_equals(
        $csrfToken,
        (string)($_POST['csrf_token'] ?? '')
    )) {
        header(
            'Location: ./saf-view.php?id=' . $safId .
            '&error=' . urlencode('Invalid security token. Please try again.')
        );
        exit;
    }

    if ($postedSafId !== $safId) {
        header(
            'Location: ./saf-view.php?id=' . $safId .
            '&error=' . urlencode('Invalid SAF selected.')
        );
        exit;
    }

    try {

        /* -------------------------------------------------
           Re-load SAF and verify current state
        ------------------------------------------------- */
        $check = $conn->prepare("
            SELECT
                s.*,
                u.name AS owner_name,
                u.email AS owner_email,
                a.display_asset_id,
                a.asset_name
            FROM saf_contracts s
            LEFT JOIN users u ON u.id = s.owner_id
            LEFT JOIN assets a ON a.id = s.asset_id
            WHERE s.id = ?
              AND s.owner_id = ?
            LIMIT 1
        ");

        if (!$check) {
            throw new Exception(
                'Unable to prepare SAF confirmation: ' . $conn->error
            );
        }

        $check->bind_param('ii', $safId, $ownerId);

        if (!$check->execute()) {
            throw new Exception(
                'Unable to load SAF confirmation data: ' . $check->error
            );
        }

        $confirmSaf = $check->get_result()->fetch_assoc();
        $check->close();

        if (!$confirmSaf) {
            throw new Exception('SAF not found.');
        }

        if (
            trim((string)($confirmSaf['status'] ?? ''))
            !== 'Pending Owner Acceptance'
        ) {
            throw new Exception(
                'This SAF is no longer waiting for Owner acceptance.'
            );
        }

        $conn->begin_transaction();

        try {

            $acceptedAt = (
                new DateTime('now', $timezone)
            )->format('Y-m-d H:i:s');

            /* ---------------------------------------------
               FINAL SAF ACCEPTANCE
               Proposed inspection date remains unchanged.
            --------------------------------------------- */
            $update = $conn->prepare("
                UPDATE saf_contracts
                SET
                    status = 'Accepted',
                    owner_accepted_at = ?,
                    updated_at = ?
                WHERE
                    id = ?
                    AND owner_id = ?
                    AND status = 'Pending Owner Acceptance'
            ");

            if (!$update) {
                throw new Exception(
                    'Unable to prepare SAF acceptance: ' . $conn->error
                );
            }

            $update->bind_param(
                'ssii',
                $acceptedAt,
                $acceptedAt,
                $safId,
                $ownerId
            );

            if (!$update->execute()) {
                throw new Exception(
                    'Unable to accept SAF: ' . $update->error
                );
            }

            if ($update->affected_rows !== 1) {
                throw new Exception(
                    'SAF could not be accepted. Its status may have changed.'
                );
            }

            $update->close();

            $conn->commit();

            /* ---------------------------------------------
               Notify Admin AFTER successful DB commit.
               Email failure must not undo acceptance.
            --------------------------------------------- */
            $adminMailSent = false;

            if (function_exists('sendNletaSafAcceptedAdminEmail')) {
                $adminMailSent = sendNletaSafAcceptedAdminEmail(
                    $safId,
                    (string)($confirmSaf['saf_no'] ?? $safNo),
                    (string)($confirmSaf['psga_no'] ?? $psgaNo),
                    (string)($confirmSaf['owner_name'] ?? $ownerName),
                    (string)($confirmSaf['owner_email'] ?? $ownerEmail),
                    (string)($confirmSaf['display_asset_id'] ?? $assetUniqueId),
                    (string)($confirmSaf['asset_name'] ?? ''),
                    (string)($confirmSaf['service_name'] ?? ''),
                    safDate($confirmSaf['proposed_inspection_date'] ?? ''),
                    $acceptedAt
                );
            } else {
                error_log(
                    'NLETA SAF Accepted Admin Email Error: ' .
                    'sendNletaSafAcceptedAdminEmail() is not available.'
                );
            }

            $acceptSuccessMessage =
                $adminMailSent
                ? 'SAF has been confirmed successfully. Admin has been notified by email.'
                : 'SAF has been confirmed successfully, but the Admin notification email could not be sent. Please check the mail configuration/logs.';

            header(
                'Location: ./saf-view.php?id=' .
                $safId .
                '&accepted=1&success=' .
                urlencode($acceptSuccessMessage)
            );
            exit;

        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

    } catch (Throwable $e) {
        header(
            'Location: ./saf-view.php?id=' .
            $safId .
            '&error=' .
            urlencode($e->getMessage())
        );
        exit;
    }
}

/* =========================================================
   HTML
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<style>

/* =========================================================
   OWNER SAF
========================================================= */

.saf-review-page {

    background:#f3f5f8;

    min-height:
        calc(100vh - 70px);

    padding:22px;
}


.saf-review-toolbar {

    max-width:850px;

    margin:
        0 auto 14px;

    display:flex;

    justify-content:
        space-between;

    align-items:center;

    gap:10px;

    flex-wrap:wrap;
}


.saf-review-toolbar .left,
.saf-review-toolbar .right {

    display:flex;

    gap:8px;

    align-items:center;

    flex-wrap:wrap;
}


.saf-status {

    max-width:850px;

    margin:
        0 auto 14px;

    background:#fff;

    border:
        1px solid #ddd;

    border-radius:8px;

    padding:
        10px 13px;

    font-size:13px;
}


.saf-sent {

    color:#055160;

    font-weight:700;
}


.saf-accepted {

    color:#0f5132;

    font-weight:700;
}


/* =========================================================
   A4
========================================================= */

.saf-paper {

    width:210mm;

    height:297mm;

    min-height:297mm;

    max-width:100%;

    margin:0 auto;

    background:#fff;

    box-sizing:border-box;

    padding:
        13mm 13mm 8mm;

    position:relative;

    color:#111;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size:14px;

    line-height:1.34;

    overflow:hidden;

    box-shadow:
        0 4px 20px
        rgba(0,0,0,.12);
}


.saf-paper::before {

    content:"NLETA";

    position:absolute;

    left:50%;

    top:51%;

    transform:
        translate(-50%, -50%)
        rotate(-25deg);

    font-size:105px;

    font-weight:800;

    letter-spacing:8px;

    color:
        rgba(100,100,100,.055);

    z-index:0;

    pointer-events:none;
}


.saf-paper > * {

    position:relative;

    z-index:1;
}


.saf-ref {

    text-align:right;

    font-size:13px;

    margin-bottom:5px;
}


.saf-title {

    text-align:center;

    font-size:24px;

    line-height:1.08;

    font-weight:800;

    text-decoration:underline;

    margin:0;
}


.saf-subtitle {

    text-align:center;

    font-size:15px;

    line-height:1.15;

    font-weight:700;

    margin:
        5px 0 16px;
}


.saf-heading {

    font-weight:800;

    margin-top:11px;

    margin-bottom:6px;
}


.saf-contract-line {

    font-weight:700;

    white-space:nowrap;

    margin:4px 0;
}


.saf-contract-line .field {

    display:inline-block;

    font-weight:400;

    border-bottom:
        1px solid #111;

    min-width:225px;

    min-height:18px;

    height:auto;

    vertical-align:bottom;

    padding:0 3px;

    white-space:normal;

    overflow-wrap:anywhere;

    word-break:break-word;
}


.saf-contract-line .saf-field {

    min-width:185px;
}


.saf-paper p {

    margin:9px 0;

    text-align:justify;
}


.saf-section {

    font-weight:800;

    margin-top:13px;

    margin-bottom:5px;
}


.saf-list {

    margin:
        5px 0 9px 23px;

    padding:0;
}


.saf-list li {

    margin:3px 0;

    padding-left:2px;
}


.saf-output-label {

    font-weight:800;
}


.saf-signatures {

    display:grid;

    grid-template-columns:
        1fr 1fr;

    column-gap:65px;

    margin-top:24px;
}


.saf-signature-title {

    font-weight:800;

    margin-bottom:12px;
}


.saf-signature-line {

    margin:8px 0;
}


.saf-bottom {

    text-align:center;

    font-weight:800;

    margin-top:auto;

    padding-top:12px;

    width:100%;
}


.saf-page-number {

    text-align:center;

    font-size:10px;

    margin-top:6px;

    width:100%;

    display:block;

    clear:both;
}


/* =========================================================
   MOBILE
========================================================= */

@media(max-width:900px) {

    .saf-review-page {

        padding:8px;
    }


    .saf-paper {

        width:100%;

        height:auto;

        min-height:0;

        padding:25px;

        font-size:11px;
    }


    .saf-title {

        font-size:17px;
    }


    .saf-subtitle {

        font-size:11px;
    }


    .saf-asset-ids {

        min-width:0;

        display:inline;

        white-space:normal;

        overflow-wrap:anywhere;

        word-break:break-word;
    }
}



/* =========================================================
   RESPONSIVE OWNER SAF VIEW
   ---------------------------------------------------------
   Desktop layout remains unchanged.
   Tablet/mobile only:
   - toolbar wraps cleanly
   - status/alerts fit viewport
   - A4 document becomes a fluid readable document
   - long fields wrap instead of causing horizontal overflow
   - signatures stack on small screens
   - template content is constrained to viewport
========================================================= */

@media (max-width: 1199.98px) {

    .saf-review-page{
        padding:16px;
    }

    .saf-review-toolbar,
    .saf-status{
        max-width:100%;
    }

    .saf-review-toolbar{
        align-items:flex-start;
    }

    .saf-review-toolbar .left,
    .saf-review-toolbar .right{
        min-width:0;
    }

    .saf-review-toolbar .right form{
        margin:0;
    }

    .saf-paper{
        max-width:100%;
        box-sizing:border-box;
    }
}

@media (max-width: 991.98px) {

    .saf-review-page{
        padding:12px;
    }

    .saf-review-toolbar{
        width:100%;
        gap:8px;
    }

    .saf-review-toolbar .left{
        flex:1 1 100%;
    }

    .saf-review-toolbar .right{
        width:100%;
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
        gap:8px;
    }

    .saf-review-toolbar .right > *,
    .saf-review-toolbar .right form,
    .saf-review-toolbar .right .btn{
        width:100%;
        min-width:0;
    }

    .saf-status{
        padding:10px 12px;
        line-height:1.5;
    }

    .saf-status .ms-2{
        margin-left:0 !important;
        display:block;
    }

    .saf-paper{
        padding:22px;
        font-size:12px;
    }

    .saf-paper table{
        width:100% !important;
        max-width:100% !important;
    }

    .saf-paper img{
        max-width:100%;
        height:auto;
    }

    .saf-contract-line{
        white-space:normal;
    }

    .saf-contract-line .field,
    .saf-contract-line .saf-field{
        min-width:0;
        max-width:100%;
        overflow-wrap:anywhere;
        word-break:break-word;
    }

    .saf-signatures{
        grid-template-columns:repeat(2,minmax(0,1fr));
        column-gap:25px;
    }
}

@media (max-width: 767.98px) {

    .saf-review-page{
        padding:8px;
    }

    .saf-review-toolbar{
        margin-bottom:10px;
    }

    .saf-review-toolbar .left{
        flex-wrap:wrap;
    }

    .saf-review-toolbar .left strong{
        flex:1 1 100%;
        font-size:14px;
    }

    .saf-review-toolbar .right{
        grid-template-columns:repeat(2,minmax(0,1fr));
    }

    .saf-status{
        border-radius:10px;
        font-size:12px;
        margin-bottom:10px;
    }

    .saf-paper{
        width:100%;
        max-width:100%;
        height:auto;
        min-height:0;
        padding:16px;
        font-size:11px;
        line-height:1.4;
        overflow:hidden;
        box-shadow:0 2px 10px rgba(0,0,0,.10);
    }

    .saf-paper::before{
        font-size:62px;
        letter-spacing:4px;
    }

    .saf-ref{
        font-size:10px;
        overflow-wrap:anywhere;
    }

    .saf-title{
        font-size:17px;
    }

    .saf-subtitle{
        font-size:11px;
        margin-bottom:12px;
    }

    .saf-section,
    .saf-heading{
        margin-top:10px;
    }

    .saf-paper p{
        margin:7px 0;
    }

    .saf-list{
        margin-left:18px;
    }

    .saf-signatures{
        grid-template-columns:1fr;
        row-gap:18px;
        column-gap:0;
        margin-top:18px;
    }

    .saf-bottom{
        padding-top:10px;
    }

    /* Prevent any included SAF template element from widening the viewport. */
    .saf-paper > div,
    .saf-paper > section,
    .saf-paper > table{
        max-width:100%;
    }

    .saf-paper table{
        display:block;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }
}

@media (max-width: 575.98px) {

    .saf-review-page{
        padding:6px;
    }

    .saf-review-toolbar .right{
        grid-template-columns:1fr 1fr;
    }

    .saf-review-toolbar .right .btn{
        font-size:11px;
        padding:7px 8px;
    }

    .saf-status{
        padding:9px 10px;
        font-size:11px;
    }

    .saf-paper{
        padding:12px;
        font-size:10px;
        line-height:1.38;
        border-radius:6px;
    }

    .saf-title{
        font-size:15px;
    }

    .saf-subtitle{
        font-size:10px;
    }

    .saf-contract-line .field,
    .saf-contract-line .saf-field{
        display:block;
        width:100%;
        margin-top:2px;
        box-sizing:border-box;
    }

    .saf-list{
        margin-left:16px;
        padding-right:2px;
    }

    .saf-signature-title{
        margin-bottom:8px;
    }
}

@media (max-width: 380px) {

    .saf-review-page{
        padding:4px;
    }

    .saf-review-toolbar .right{
        grid-template-columns:1fr;
    }

    .saf-paper{
        padding:10px;
        font-size:9.5px;
    }

    .saf-title{
        font-size:14px;
    }

    .saf-paper::before{
        font-size:48px;
    }
}



/* =========================================================
   MOBILE / TABLET — TRUE HORIZONTAL SCROLL
   ---------------------------------------------------------
   Keep the SAF document at its original A4 width.
   Do NOT squeeze the document into the phone width.
   The surrounding viewport scrolls horizontally.
========================================================= */

.saf-document-scroll{
    width:100%;
    max-width:100%;
    overflow-x:auto;
    overflow-y:visible;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
    box-sizing:border-box;
    padding:0 4px 12px;
}

.saf-document-stage{
    width:max-content;
    min-width:210mm;
    margin:0 auto;
    box-sizing:border-box;
}

/* Keep every generated SAF page at A4 proportions. */
.saf-document-stage .saf-paper{
    width:210mm !important;
    min-width:210mm !important;
    max-width:none !important;
    height:297mm !important;
    min-height:297mm !important;
    box-sizing:border-box !important;
    margin-left:auto !important;
    margin-right:auto !important;
    overflow:hidden !important;
}

/* Disable the previous mobile squeeze/reflow rules. */
@media screen and (max-width:900px){

    .saf-review-page{
        width:100%;
        max-width:100%;
        padding:8px;
        box-sizing:border-box;
        overflow:visible !important;
    }

    .saf-document-scroll{
        width:100%;
        max-width:100%;
        overflow-x:auto !important;
        overflow-y:visible;
        padding:0 4px 14px;
        box-sizing:border-box;
    }

    .saf-document-stage{
        width:max-content !important;
        min-width:210mm !important;
        margin:0 auto !important;
        display:block;
    }

    .saf-document-stage .saf-paper{
        width:210mm !important;
        min-width:210mm !important;
        max-width:none !important;
        height:297mm !important;
        min-height:297mm !important;
        padding:13mm 13mm 8mm !important;
        font-size:14px !important;
        line-height:1.34 !important;
        box-sizing:border-box !important;
        overflow:hidden !important;
        box-shadow:0 3px 14px rgba(0,0,0,.12) !important;
    }

    .saf-document-stage .saf-title{
        font-size:24px !important;
    }

    .saf-document-stage .saf-subtitle{
        font-size:15px !important;
    }

    .saf-document-stage .saf-contract-line{
        white-space:nowrap !important;
    }

    .saf-document-stage .saf-contract-line .field,
    .saf-document-stage .saf-contract-line .saf-field{
        min-width:185px !important;
        max-width:none !important;
        display:inline-block !important;
    }

    .saf-document-stage .saf-signatures{
        grid-template-columns:1fr 1fr !important;
        column-gap:65px !important;
    }

    .saf-document-stage table{
        width:100% !important;
        max-width:none !important;
    }

    .saf-document-stage img{
        max-width:100%;
        height:auto;
    }

    /* Make the scrollbar clearly available on touch screens. */
    .saf-document-scroll::-webkit-scrollbar{
        height:10px;
    }

    .saf-document-scroll::-webkit-scrollbar-thumb{
        background:#9aa4b2;
        border-radius:8px;
    }

    .saf-document-scroll::-webkit-scrollbar-track{
        background:#e9edf2;
        border-radius:8px;
    }
}

@media screen and (max-width:575.98px){

    .saf-document-scroll{
        padding-left:2px;
        padding-right:2px;
    }

    .saf-document-stage{
        margin-left:0 !important;
        margin-right:0 !important;
    }
}


/* =========================================================
   PRINT
========================================================= */

@page {

    size:A4 portrait;

    margin:0;
}


@media print {

    /* =====================================================
       FINAL OWNER SAF PRINT — MULTI-PAGE A4
       IMPORTANT: Never lock the complete document to one
       297mm page and never hide overflow.
    ===================================================== */

    html,
    body {
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
    .saf-status {
        display:none !important;
    }

    .content,
    .main-content,
    .page-content,
    .dashboard-content,
    .container,
    .container-fluid {
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        left:auto !important;
        right:auto !important;
        top:auto !important;
        position:static !important;
        overflow:visible !important;
    }

    .saf-review-page {
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
        overflow:visible !important;
        position:static !important;
    }

    .saf-document-scroll {
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
        display:block !important;
        position:static !important;
    }

    .saf-document-stage {
        width:100% !important;
        max-width:none !important;
        height:auto !important;
        min-height:0 !important;
        margin:0 !important;
        padding:0 !important;
        overflow:visible !important;
        display:block !important;
        position:static !important;
    }

    /* Actual SAF paper: A4 width, automatic height. */
    .saf-paper {
        width:210mm !important;
        max-width:210mm !important;
        height:auto !important;
        min-height:0 !important;
        max-height:none !important;
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

    /* DGS shared template is also allowed to flow to page 2+. */
    .saf-dgs-paper,
    .saf-pat-paper,
    .saf-sat-paper,
    .saf-sci-paper {
        width:210mm !important;
        max-width:210mm !important;
        height:auto !important;
        min-height:0 !important;
        max-height:none !important;
        margin:0 auto !important;
        overflow:visible !important;
        box-shadow:none !important;
        page-break-before:auto !important;
        page-break-after:auto !important;
        page-break-inside:auto !important;
        break-before:auto !important;
        break-after:auto !important;
        break-inside:auto !important;
    }

    /* Keep logical blocks together when possible. */
    .saf-signatures,
    .saf-dgs-signatures,
    .saf-dgs-contract {
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }

    .saf-list,
    .saf-dgs-list {
        break-inside:auto !important;
        page-break-inside:auto !important;
    }

    .saf-paper p,
    .saf-dgs-paper p {
        orphans:3 !important;
        widows:3 !important;
        break-inside:auto !important;
        page-break-inside:auto !important;
    }

    /* DGS footer must remain in normal document flow. */
    .saf-dgs-footer {
        position:static !important;
        left:auto !important;
        right:auto !important;
        bottom:auto !important;
        margin-top:18mm !important;
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }

    .saf-bottom {
        margin-top:18mm !important;
        padding-top:12px !important;
        text-align:center !important;
        width:100% !important;
    }

    .saf-page-number {
        text-align:center !important;
        width:100% !important;
        margin-top:5px !important;
        padding-bottom:0 !important;
    }

    .saf-paper::before {
        display:block !important;
    }

    .row,
    .card,
    .card-body {
        margin:0 !important;
        padding:0 !important;
        border:0 !important;
        box-shadow:none !important;
        overflow:visible !important;
    }

    * {
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
            href="./saf-list.php"
            class="btn btn-outline-secondary btn-sm"
        >

            <i class="bi bi-arrow-left me-1"></i>

            Back

        </a>


        <strong>

            SAF - Service Activation Form

        </strong>

    </div>


    <div class="right">


        <button
            type="button"
            class="btn btn-outline-dark btn-sm"
            title="For a clean document, turn off Browser Headers and Footers in the print dialog."
            onclick="window.print()"
        >

            <i class="bi bi-printer me-1"></i>

            Print

        </button>


        <?php if ($status === 'Pending Owner Acceptance'): ?>

            <form
                method="POST"
                onsubmit="return confirm('Are you sure you want to confirm this SAF? Once confirmed, the SAF will be marked Accepted and Admin will be notified by email.');"
            >

                <input
                    type="hidden"
                    name="saf_id"
                    value="<?= (int)$safId ?>"
                >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <button
                    type="submit"
                    name="confirm_saf"
                    class="btn btn-success btn-sm"
                >

                    <i class="bi bi-check-circle me-1"></i>

                    Confirm SAF

                </button>

            </form>

        <?php endif; ?>


    </div>

</div>


<!-- =====================================================
     SUCCESS
====================================================== -->

<?php if ($accepted): ?>

    <div
        class="
            saf-status
            alert
            alert-success
            no-print
        "
    >

        <i class="bi bi-check-circle-fill me-1"></i>

        SAF has been confirmed successfully. Admin has been notified by email.

    </div>

<?php endif; ?>


<?php if ($success !== ''): ?>

    <div
        class="
            saf-status
            alert
            alert-success
            no-print
        "
    >

        <i class="bi bi-envelope-check me-1"></i>

        <?= h($success) ?>

    </div>

<?php endif; ?>


<?php if ($error !== ''): ?>

    <div
        class="
            saf-status
            alert
            alert-danger
            no-print
        "
    >

        <i class="bi bi-exclamation-triangle me-1"></i>

        <?= h($error) ?>

    </div>

<?php endif; ?>


<!-- =====================================================
     STATUS
====================================================== -->

<div class="saf-status no-print">

    <strong>Status:</strong>


    <?php if (
        $status ===
        'Pending Owner Acceptance'
    ): ?>

        <span class="saf-sent">

            Pending Owner Acceptance

        </span>


        <?php if (
            !$isDgsSaf
            &&
            !empty(
                $saf['proposed_inspection_date']
            )
        ): ?>

            <span class="text-muted ms-2">

                Proposed Inspection Date:

                <strong>

                    <?= h(
                        safDate(
                            $saf[
                                'proposed_inspection_date'
                            ]
                        )
                    ) ?>

                </strong>

            </span>

        <?php endif; ?>


        <span class="text-muted ms-2">

            Please click Confirm SAF to complete acceptance.

        </span>


    <?php elseif (
        $status ===
        'Accepted'
    ): ?>

        <span class="saf-accepted">

            Accepted

        </span>


        <?php if (
            !empty(
                $saf['owner_accepted_at']
            )
        ): ?>

            <span class="text-muted ms-2">

                Accepted on:

                <?= h(
                    safDate(
                        $saf[
                            'owner_accepted_at'
                        ]
                    )
                ) ?>

            </span>

        <?php endif; ?>


    <?php else: ?>

        <?= h($status) ?>

    <?php endif; ?>

</div>


<!-- =====================================================
     MOBILE DOCUMENT SCROLL VIEWPORT
====================================================== -->
<div class="saf-document-scroll no-print-mobile-wrapper">
    <div class="saf-document-stage">

<!-- =====================================================
     A4 SAF DOCUMENT — SERVICE-SPECIFIC TEMPLATE
     ---------------------------------------------------------
     OWNER USES THE SAME APPROVED SAF TEMPLATES AS ADMIN.

     Template source:
         ../admin/saf-templates/saf-pat.php
         ../admin/saf-templates/saf-sat.php
         ../admin/saf-templates/saf-sci.php
         ../admin/saf-templates/saf-dgs.php

     The SAF record is fetched from saf_contracts and passed
     directly to the selected template.

     No separate Owner template copy is created.
====================================================== -->

<?php
/* =========================================================
   OWNER SERVICE TEMPLATE SELECTOR
========================================================= */

/* DGS / DG is always routed to the dedicated DGS template. */

$ownerServiceCode = strtoupper(
    trim((string)($saf['service_code'] ?? ''))
);

if ($ownerServiceCode === '') {
    $ownerServiceCode = strtoupper(
        trim((string)($saf['saf_type'] ?? ''))
    );
}

/*
 * Normalize common legacy SCI values.
 */
$ownerServiceKey = preg_replace(
    '/[^A-Z0-9]+/',
    '_',
    $ownerServiceCode
);

$ownerServiceName = strtoupper(
    trim((string)($saf['service_name'] ?? ''))
);

/*
 * Digital Governance Support uses DG in the existing
 * service catalog, while newer SAF records may use DGS.
 * Both values must open the dedicated DGS SAF template.
 */
if (
    $ownerServiceKey === 'DG'
    || $ownerServiceKey === 'DGS'
    || strpos($ownerServiceName, 'DIGITAL GOVERNANCE') !== false
    || strpos($ownerServiceName, 'GOVERNANCE SUPPORT') !== false
) {
    $ownerServiceKey = 'DGS';
}

if (
    $ownerServiceKey === 'PAT_SAT'
    || $ownerServiceKey === 'PAT_SAT_'
    || strpos($ownerServiceName, 'STANDARDS COMPLIANCE') !== false
    || strpos($ownerServiceName, 'PAT + SAT') !== false
    || strpos($ownerServiceName, 'PAT+SAT') !== false
) {
    $ownerServiceKey = 'SCI';
}

/*
 * If service_code is missing, identify the service from the
 * stored service name for older SAF records.
 */
if ($ownerServiceKey === '') {

    if (
        strpos($ownerServiceName, 'SATISFACTORY ACCEPTANCE') !== false
        || strpos($ownerServiceName, 'QUALITY INSPECTION') !== false
    ) {
        $ownerServiceKey = 'SAT';

    } elseif (
        strpos($ownerServiceName, 'PARTIAL ACCEPTANCE') !== false
        || strpos($ownerServiceName, 'SAFETY INSPECTION') !== false
    ) {
        $ownerServiceKey = 'PAT';
    }
}

$ownerTemplateFile = '';

switch ($ownerServiceKey) {

    case 'DGS':

        $ownerTemplateFile =
            __DIR__ .
            '/../admin/saf-templates/saf-dgs.php';

        break;

    case 'PAT':

        $ownerTemplateFile =
            __DIR__ .
            '/../admin/saf-templates/saf-pat.php';

        break;

    case 'SAT':

        $ownerTemplateFile =
            __DIR__ .
            '/../admin/saf-templates/saf-sat.php';

        break;

    case 'SCI':

        $ownerTemplateFile =
            __DIR__ .
            '/../admin/saf-templates/saf-sci.php';

        break;

    default:

        die(
            'Unsupported SAF service for Owner: ' .
            h(
                $ownerServiceCode
                ?: ($ownerServiceName ?: 'Unknown')
            )
        );
}

if (!is_file($ownerTemplateFile)) {

    die(
        'SAF template file is missing: ' .
        h(basename($ownerTemplateFile))
    );
}

/*
 * Shared templates already receive $saf and $conn.
 * This flag can be used by the template if it needs to
 * distinguish Owner rendering from Admin rendering.
 */
$isOwnerSafTemplate = true;

/*
 * The existing Admin templates contain watermark image
 * paths relative to /admin/. When they are included from
 * /owner/saf-view.php, the browser would otherwise look in
 * /owner/saf-templates/.
 *
 * We therefore mark the template images for a small URL
 * correction after rendering. Document content is unchanged.
 */
include $ownerTemplateFile;
?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    document
        .querySelectorAll(
            '.saf-template-watermark img,' +
            '.sat-template-watermark img,' +
            '.sci-template-watermark img'
        )
        .forEach(function (img) {

            const src =
                img.getAttribute('src') || '';

            if (
                src.indexOf('saf-templates/') === 0
            ) {
                img.setAttribute(
                    'src',
                    '../admin/' + src
                );
            }
        });
});
</script>

    </div><!-- /.saf-document-stage -->
</div><!-- /.saf-document-scroll -->

</div>

</div>

<?php include('../includes/footer.php'); ?>