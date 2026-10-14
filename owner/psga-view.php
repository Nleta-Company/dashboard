<?php
/* =========================================================
   NLETA OWNER — PSGA VIEW
   ---------------------------------------------------------
   Dedicated Owner PSGA view page.

   IMPORTANT:
   The PSGA DOCUMENT FORMAT below is copied from the Admin
   PSGA Agreement document format without changing the
   agreement wording, headings, services, tables, watermark,
   header, footer or page structure.

   Owner can only view his/her own PSGA.
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');
date_default_timezone_set('Asia/Kolkata');

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function formatISTDateOnly($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }

    try {
        return (new DateTime((string)$value))->format('d-m-Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

$ownerId = (int)($_SESSION['uid'] ?? 0);
$psgaId  = (int)($_GET['psga_id'] ?? 0);

if ($ownerId <= 0 || $psgaId <= 0) {
    header('Location: approvals.php');
    exit;
}

/* =========================================================
   LOAD ONLY THIS OWNER'S PSGA
========================================================= */
$stmt = $conn->prepare("
    SELECT
        c.PSGA_ID,
        c.PSGA_No,
        c.Asset_ID,
        c.Contract_Date,
        c.Status,
        c.Owner_ID,
        c.Owner_Name,
        c.Owner_Address,
        c.Authorized_Representative,
        c.Selected_Services,

        (
            SELECT ad.consignee_name
            FROM nleta_offer_assets oa_rep
            INNER JOIN assets a_rep ON a_rep.id = oa_rep.asset_id
            INNER JOIN asset_details ad ON ad.asset_id = a_rep.id
            INNER JOIN nleta_offer_master om_rep ON om_rep.id = oa_rep.offer_id
            WHERE om_rep.psga_no = c.PSGA_No
              AND om_rep.id = (
                  SELECT MAX(om_latest_rep.id)
                  FROM nleta_offer_master om_latest_rep
                  WHERE om_latest_rep.psga_no = c.PSGA_No
              )
              AND ad.consignee_name IS NOT NULL
              AND TRIM(ad.consignee_name) <> ''
            ORDER BY oa_rep.id ASC, ad.id DESC
            LIMIT 1
        ) AS display_representative,

        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    COALESCE(oi.service_code, ''),
                    '||',
                    COALESCE(oi.service_name, '')
                )
                ORDER BY om_service.id ASC, oi.id ASC
                SEPARATOR '|||SERVICE||| '
            )
            FROM nleta_offer_master om_service
            INNER JOIN nleta_offer_items oi
                ON oi.offer_id = om_service.id
            WHERE om_service.psga_no = c.PSGA_No
        ) AS fso_services,


        u.name AS user_owner_name,
        u.email AS owner_email,
        u.phone AS owner_phone,
        u.organization_name AS organization_name,
        u.organization_address AS organization_address,

        am_primary.Asset_Name AS asset_name,
        am_primary.Display_Asset_ID AS display_asset_id,
        am_primary.Location AS location,

        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    COALESCE(NULLIF(TRIM(a2.display_asset_id), ''), CONCAT('ASSET-', a2.id)),
                    ' — ',
                    COALESCE(a2.asset_name, ''),
                    CASE
                        WHEN a2.location IS NOT NULL
                             AND TRIM(a2.location) <> ''
                        THEN CONCAT(' (', a2.location, ')')
                        ELSE ''
                    END
                )
                ORDER BY
                    COALESCE(NULLIF(TRIM(a2.display_asset_id), ''), CONCAT('ASSET-', a2.id)),
                    a2.id
                SEPARATOR '||'
            )
            FROM nleta_offer_assets oa2
            INNER JOIN assets a2
                ON a2.id = oa2.asset_id
            INNER JOIN nleta_offer_master om2
                ON om2.id = oa2.offer_id
            WHERE om2.psga_no = c.PSGA_No
        ) AS all_assets,

        (
            SELECT COUNT(DISTINCT oa3.asset_id)
            FROM nleta_offer_assets oa3
            INNER JOIN nleta_offer_master om3
                ON om3.id = oa3.offer_id
            WHERE om3.psga_no = c.PSGA_No
        ) AS total_psga_assets,

        (
            SELECT COUNT(DISTINCT om4.id)
            FROM nleta_offer_master om4
            WHERE om4.psga_no = c.PSGA_No
        ) AS total_psga_fsos

    FROM contracts c

    LEFT JOIN users u
        ON u.id = c.Owner_ID

    LEFT JOIN asset_master am_primary
        ON am_primary.Asset_ID = c.Asset_ID

    WHERE c.PSGA_ID = ?
      AND c.Owner_ID = ?
    LIMIT 1
");

if (!$stmt) {
    die('Unable to load PSGA: ' . h($conn->error));
}

$stmt->bind_param('ii', $psgaId, $ownerId);

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    die('Unable to load PSGA: ' . h($err));
}

$p = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$p) {
    $_SESSION['error'] = 'PSGA Agreement not found or access denied.';
    header('Location: approvals.php');
    exit;
}

/* =========================================================
   ASSET ITEMS — SAME DISPLAY LOGIC AS ADMIN DOCUMENT
========================================================= */
$p['asset_items'] = [];

if (!empty($p['all_assets'])) {
    $seenAssets = [];

    foreach (explode('||', (string)$p['all_assets']) as $part) {
        $part = trim($part);

        if ($part === '') {
            continue;
        }

        /*
         * First portion is the Display Asset ID. Use it as the
         * uniqueness key so the same asset is not repeated when
         * it appears in more than one FSO.
         */
        $assetKey = trim((string)explode(' — ', $part, 2)[0]);

        if ($assetKey === '') {
            $assetKey = $part;
        }

        $assetKey = strtolower($assetKey);

        if (isset($seenAssets[$assetKey])) {
            continue;
        }

        $seenAssets[$assetKey] = true;
        $p['asset_items'][] = $part;
    }
}

if (!$p['asset_items']) {
    $fallback = trim(
        (string)($p['display_asset_id'] ?: $p['asset_name'] ?: '')
    );

    if ($fallback !== '') {
        $p['asset_items'][] = $fallback;
    }
}

/* =========================================================
   OWNER DISPLAY FALLBACKS
========================================================= */
$p['display_owner'] = trim((string)($p['Owner_Name'] ?? ''));

if ($p['display_owner'] === '') {
    $p['display_owner'] = trim((string)($p['user_owner_name'] ?? ''));
}

if ($p['display_owner'] === '') {
    $p['display_owner'] = '________________________';
}

/* =========================================================
   PERMANENT ORGANIZATION / ASSOCIATION DETAILS
   ---------------------------------------------------------
   These values come from the Owner profile and are used by
   the shared PSGA document.
========================================================= */
$p['organization_name'] = trim((string)($p['organization_name'] ?? ''));
$p['organization_address'] = trim((string)($p['organization_address'] ?? ''));

/*
 * The shared Admin PSGA renderer uses these exact keys.
 * Keep the Owner profile fields as the source of truth.
 */
$p['display_association_name'] = $p['organization_name'];
$p['display_association_address'] = $p['organization_address'];
if ($p['display_association_name'] === '') {
    $p['display_association_name'] = $p['display_owner'];
}
if ($p['display_association_address'] === '') {
    $p['display_association_address'] = trim((string)($p['Owner_Address'] ?? ''));
}



/* Owner-supplied representative: prefer asset_details.consignee_name. */
$p['display_representative'] = trim((string)($p['display_representative'] ?? ''));

if ($p['display_representative'] === '') {
    $p['display_representative'] = trim((string)($p['Authorized_Representative'] ?? ''));
}

if ($p['display_representative'] === '') {
    $p['display_representative'] = $p['display_owner'];
}

/* FSO saved services are the source of truth for the common document. */
$p['approved_service_codes'] = [];
$p['approved_service_names'] = [];

if (!empty($p['fso_services'])) {
    foreach (explode('|||SERVICE|||', (string)$p['fso_services']) as $servicePart) {
        $servicePart = trim($servicePart);
        if ($servicePart === '') continue;

        $pieces = explode('||', $servicePart, 2);
        $code = strtoupper(trim((string)($pieces[0] ?? '')));
        $name = trim((string)($pieces[1] ?? ''));

        if ($code !== '') $p['approved_service_codes'][] = $code;
        if ($name !== '') $p['approved_service_names'][] = $name;
    }

    $p['approved_service_codes'] = array_values(array_unique($p['approved_service_codes']));
}

/* Keep the same status visible on the page, but never alter
   the PSGA document itself. */
$status = trim((string)($p['Status'] ?? ''));
if ($status === '') {
    $status = 'Pending Admin Review';
}

include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<style>
.psga-page{padding:24px;max-width:1500px}
.psga-header{background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07);padding:22px;margin-bottom:20px}
.status{display:inline-block;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:600}
.s-owner{background:#fff3cd;color:#856404}
.s-admin{background:#cff4fc;color:#055160}
.s-confirmed{background:#d1e7dd;color:#0f5132}
.s-review{background:#e2e3e5;color:#41464b}
@media(max-width:900px){.psga-page{padding:12px}}
@media print{
    /*
     * OWNER PSGA PRINT
     * Keep the Owner print output identical to the shared/Admin PSGA:
     * A4 portrait, fixed 4 pages, same margins, typography, header,
     * footer, watermark and page breaks.
     */
    @page{
        size:A4 portrait;
        margin:0;
    }

    html,
    body{
        margin:0!important;
        padding:0!important;
        width:210mm!important;
        min-width:210mm!important;
        background:#fff!important;
    }

    body *{
        visibility:hidden!important;
    }

    #psgaPrintArea,
    #psgaPrintArea *{
        visibility:visible!important;
    }

    #psgaPrintArea{
        position:absolute!important;
        left:0!important;
        top:0!important;
        width:210mm!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }

    #psgaPrintArea .psga-page-paper{
        width:210mm!important;
        height:297mm!important;
        min-height:297mm!important;
        max-height:297mm!important;
        box-sizing:border-box!important;

        margin:0!important;
        padding:18mm 15mm 16mm!important;

        border:0!important;
        border-radius:0!important;
        box-shadow:none!important;
        background:#fff!important;

        position:relative!important;
        overflow:hidden!important;

        page-break-before:auto!important;
        page-break-after:always!important;
        break-before:auto!important;
        break-after:page!important;

        font-family:Arial,Helvetica,sans-serif!important;
        font-size:10.8pt!important;
        line-height:1.42!important;
    }

    #psgaPrintArea .psga-page-paper:last-child{
        page-break-after:auto!important;
        break-after:auto!important;
    }

    /* ---------- SAME HEADER AS ADMIN / SHARED PSGA ---------- */
    #psgaPrintArea .psga-doc-header,
    #psgaPrintArea .psga-doc-header.fso-style-header{
        display:flex!important;
        align-items:center!important;
        gap:8px!important;
        border-bottom:2px solid #111!important;
        padding-bottom:4px!important;
        margin-bottom:6px!important;
    }

    #psgaPrintArea .psga-doc-logo,
    #psgaPrintArea .fso-style-logo{
        width:70px!important;
        height:70px!important;
        flex:0 0 70px!important;
        object-fit:contain!important;
    }

    #psgaPrintArea .fso-style-agency{
        flex:1!important;
        text-align:center!important;
        line-height:1.1!important;
    }

    #psgaPrintArea .fso-style-agency-name{
        font-size:24px!important;
        line-height:1.05!important;
        font-weight:700!important;
    }

    #psgaPrintArea .fso-style-tagline{
        font-size:12px!important;
        font-weight:700!important;
        line-height:1.1!important;
        margin-top:2px!important;
    }

    #psgaPrintArea .fso-style-approval{
        font-size:10px!important;
        font-weight:700!important;
        line-height:1.1!important;
        margin-top:2px!important;
    }

    #psgaPrintArea .fso-style-subtitle{
        font-size:9.5px!important;
        font-weight:600!important;
        line-height:1.1!important;
        margin-top:2px!important;
    }

    /* ---------- SAME DOCUMENT TYPOGRAPHY ---------- */
    #psgaPrintArea .psga-page-paper h1{
        font-size:13pt!important;
        line-height:1.15!important;
        margin:4px 0 5px!important;
        font-weight:800!important;
    }

    #psgaPrintArea .psga-page-paper h2{
        font-size:12.5pt!important;
        line-height:1.22!important;
        margin:13px 0 5px!important;
        padding-bottom:3px!important;
    }

    #psgaPrintArea .psga-page-paper p{
        font-size:10.8pt!important;
        line-height:1.42!important;
        margin:5px 0!important;
    }

    /* ---------- POINT 2: ALWAYS 2 COLUMNS IN PRINT ---------- */
    #psgaPrintArea .psga-page-paper .psga-services.psga-services-two-column{
        display:grid!important;
        grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;
        column-gap:28px!important;
        row-gap:0!important;
        width:100%!important;
        box-sizing:border-box!important;
        align-items:start!important;
        margin-top:6px!important;
    }

    #psgaPrintArea .psga-page-paper .psga-services.psga-services-two-column .psga-service-column{
        display:block!important;
        width:100%!important;
        min-width:0!important;
    }

    #psgaPrintArea .psga-page-paper .psga-services.psga-services-two-column .psga-service-option{
        display:flex!important;
        width:100%!important;
        margin:4px 0!important;
        break-inside:avoid!important;
        page-break-inside:avoid!important;
        line-height:1.25!important;
    }

    /* ---------- TABLES ---------- */
    #psgaPrintArea .psga-table-doc{
        width:100%!important;
        border-collapse:collapse!important;
        margin-top:8px!important;
    }

    #psgaPrintArea .psga-table-doc th,
    #psgaPrintArea .psga-table-doc td{
        padding:6px 7px!important;
        font-size:10pt!important;
        line-height:1.25!important;
    }

    #psgaPrintArea .psga-table-doc tr{
        break-inside:avoid!important;
        page-break-inside:avoid!important;
    }

    /* ---------- SIGNATURES ---------- */
    #psgaPrintArea .psga-signatures{
        display:grid!important;
        grid-template-columns:1fr 1fr!important;
        gap:35px!important;
        margin-top:20px!important;
    }

    #psgaPrintArea .psga-signatures .line{
        min-height:28px!important;
        margin-bottom:8px!important;
    }

    /* ---------- WATERMARK ---------- */
    #psgaPrintArea .psga-watermark{
        position:absolute!important;
        z-index:0!important;
        opacity:.075!important;
        pointer-events:none!important;
    }

    /* ---------- FOOTER ---------- */
    #psgaPrintArea .fso-style-footer{
        position:absolute!important;
        left:0!important;
        right:0!important;
        bottom:5mm!important;

        border-top:2px solid #111!important;
        padding-top:2px!important;

        background:#fff!important;
        text-align:center!important;
        font-size:9.5px!important;
        line-height:1.3!important;
        z-index:20!important;
    }

    #psgaPrintArea .psga-page-number{
        position:absolute!important;
        right:11mm!important;
        bottom:5.2mm!important;
        font-size:8.5px!important;
        line-height:1.2!important;
        background:#fff!important;
        padding-left:4px!important;
        z-index:21!important;
    }

    .no-print,
    .print-btn{
        display:none!important;
    }
}
</style>

<div class="content">
<div class="psga-page">

    <!-- =================================================
         OWNER TOOLBAR — OUTSIDE THE PSGA DOCUMENT
    ================================================== -->
    <div class="psga-header no-print">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

            <div>
                <h3 class="mb-1">
                    <i class="bi bi-file-earmark-text me-2"></i>
                    PSGA Agreement
                </h3>

                <div class="text-muted">
                    Permanent Professional Service &amp; Governance Agreement
                    &nbsp;•&nbsp;
                    Ref. No.: <strong><?=h($p['PSGA_No'])?></strong>
                </div>
            </div>

            <div class="d-flex gap-2 align-items-center">

                <span class="status
                    <?=
                    $status === 'Confirmed'
                        ? 's-confirmed'
                        : ($status === 'Pending Owner Confirmation'
                            ? 's-owner'
                            : ($status === 'Pending Admin Confirmation'
                                ? 's-admin'
                                : 's-review'))
                    ?>">
                    <?=h($status)?>
                </span>

                <button type="button"
                        class="btn btn-outline-primary"
                        onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>
                    Print PSGA
                </button>

                <a href="approvals.php" class="btn btn-secondary">
                    <i class="bi bi-arrow-left me-1"></i>
                    Back
                </a>
            </div>

        </div>
    </div>

    <!-- =================================================
         EXACT SAME 4-PAGE PSGA DOCUMENT AS ADMIN
    ================================================== -->
    <?php
/* =========================================================
   SHARED PSGA DOCUMENT
   The exact same 4-page renderer is used by Admin and Owner.
========================================================= */
require_once('../admin/saf-templates/psga-generator.php');
renderNletaPsga($p, true);
?>

</div>
</div>

<?php include('../includes/footer.php'); ?>