<?php
/* =========================================================
   NLETA — SAF-PAT TEMPLATE
   ---------------------------------------------------------
   This file is loaded by admin/saf-view.php.

   PAT content is preserved.
   Layout is isolated and matched to the SAT / SCI A4 format.
   Dynamic values are resolved here.
========================================================= */

$templateSaf = (isset($saf) && is_array($saf)) ? $saf : [];

/* ---------- Dynamic document values ---------- */

$templatePsgaNo = trim((string)($templateSaf['psga_no'] ?? ''));
if ($templatePsgaNo === '') {
    $templatePsgaNo = '____________________________';
}

$templateSafNo = trim((string)($templateSaf['saf_no'] ?? ''));
if ($templateSafNo === '') {
    $templateSafNo = '______________________';
}

$templateActivationDate = trim((string)($templateSaf['activation_date'] ?? ''));
if ($templateActivationDate !== '') {
    try {
        $templateActivationDate = (new DateTime(
            $templateActivationDate,
            new DateTimeZone('Asia/Kolkata')
        ))->format('d / m / Y');
    } catch (Throwable $e) {
        $templateActivationDate = '____ / ____ / ______';
    }
} else {
    $templateActivationDate = '____ / ____ / ______';
}

$templateInspectionDate = trim(
    (string)($templateSaf['proposed_inspection_date'] ?? '')
);

if ($templateInspectionDate !== '') {
    try {
        $templateInspectionDate = (new DateTime(
            $templateInspectionDate,
            new DateTimeZone('Asia/Kolkata')
        ))->format('d / m / Y');
    } catch (Throwable $e) {
        $templateInspectionDate = '____ / ____ / ______';
    }
} else {
    $templateInspectionDate = '____ / ____ / ______';
}

$templateServiceName = trim(
    (string)($templateSaf['service_name'] ?? '')
);

if ($templateServiceName === '') {
    $templateServiceName = strtoupper(
        trim((string)($templateSaf['service_code'] ?? 'Selected Service'))
    );
}

if ($templateServiceName === '') {
    $templateServiceName = 'Selected Service';
}

$templateChecklist = trim(
    (string)($templateSaf['checklist_reference'] ?? '')
);

if ($templateChecklist === '') {
    $templateChecklist = '';
}

$templateAssetUniqueId = trim(
    (string)($templateSaf['display_asset_id'] ?? '')
);

if ($templateAssetUniqueId === '') {

    $templateAssetId = (int)($templateSaf['asset_id'] ?? 0);

    if (
        $templateAssetId > 0 &&
        isset($conn) &&
        $conn instanceof mysqli
    ) {

        $assetStmt = $conn->prepare("
            SELECT display_asset_id
            FROM assets
            WHERE id = ?
            LIMIT 1
        ");

        if ($assetStmt) {

            $assetStmt->bind_param(
                'i',
                $templateAssetId
            );

            if ($assetStmt->execute()) {

                $assetRow = $assetStmt
                    ->get_result()
                    ->fetch_assoc();

                if ($assetRow) {
                    $templateAssetUniqueId = trim(
                        (string)($assetRow['display_asset_id'] ?? '')
                    );
                }
            }

            $assetStmt->close();
        }
    }
}

if ($templateAssetUniqueId === '') {
    $templateAssetUniqueId = '________________________';
}

/* ---------- Approved PAT document reference ---------- */

$serviceRef = 'NLETA/SAF-PAT/2026 (Rev.1)';
$serviceTitle = '“PAT (PARTIAL ACCEPTANCE TEST / SAFETY INSPECTION)”';
?>

<style>
/* =========================================================
   NLETA — SAF-PAT
   SAT / SCI MATCHED A4 DOCUMENT LAYOUT
   ---------------------------------------------------------
   All classes are PAT-specific so this template does not
   interfere with saf-view.php or SAT / SCI CSS.
========================================================= */

.pat-template-paper{
    width:210mm;
    height:297mm;
    min-height:297mm;
    max-width:100%;
    margin:0 auto;
    padding:8mm 12mm 6mm;
    box-sizing:border-box;
    position:relative;
    overflow:hidden;
    background:#fff;
    color:#111;
    font-family:Arial, Helvetica, sans-serif;
    font-size:14px;
    line-height:1.25;
}

/* Keep every document element above the watermark. */
.pat-template-paper > *{
    position:relative;
    z-index:2;
}

/* =========================================================
   NLETA LOGO WATERMARK
========================================================= */

.pat-template-watermark{
    position:absolute !important;
    z-index:1 !important;
    left:50%;
    top:51%;
    width:172mm;
    max-width:78%;
    transform:translate(-50%,-50%);
    opacity:.09;
    pointer-events:none;
    user-select:none;
}

.pat-template-watermark img{
    display:block;
    width:100%;
    height:auto;
}

/* =========================================================
   HEADER / TITLE
========================================================= */

.pat-ref{
    text-align:right;
    font-size:13px;
    margin:0 0 32px;
}

.pat-main-title{
    text-align:center;
    font-size:19px;
    line-height:1.08;
    font-weight:700;
    text-decoration:underline;
    margin:0;
}

.pat-sub-title{
    text-align:center;
    font-size:15px;
    line-height:1.08;
    font-weight:700;
    margin:3px 0 10px;
}

/* =========================================================
   >>> PAT HEADER ONLY — SAFE EDIT POINT <<<
   ---------------------------------------------------------
   Only header values / spacing should be adjusted here.
========================================================= */

.pat-header-block{
    width:100%;
    margin:0 0 10px;
}

.pat-header-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:14mm;
    width:100%;
    align-items:center;
    margin:0 0 5px;
}

.pat-header-cell{
    display:grid;
    grid-template-columns:max-content 1fr;
    align-items:center;
    column-gap:2.5mm;
    min-width:0;
    font-weight:700;
    white-space:nowrap;
}

.pat-header-label{
    display:block;
    white-space:nowrap;
}

.pat-header-field{
    display:block;
    width:100%;
    min-width:0;
    height:5.5mm;
    box-sizing:border-box;
    padding:0 1.5mm 0.8mm;
    border-bottom:0.3mm solid #222;
    font-weight:400;
    line-height:1.1;
    overflow:hidden;
    white-space:nowrap;
}

/* =========================================================
   <<< END PAT HEADER ONLY — SAFE EDIT POINT >>>
========================================================= */

/* ---------- Body ---------- */

.pat-template-paper p{
    margin:7px 0;
    text-align:justify;
}

.pat-section{
    margin-top:16px;
    margin-bottom:5px;
    text-align:justify;
    font-weight:700;
}

.pat-section .pat-normal{
    font-weight:400;
}

.pat-service-row{
    font-weight:700;
    text-align:justify;
    margin:3px 0 8px;
}

.pat-service-row .pat-field{
    min-width:39mm;
}

.pat-list{
    margin:2px 0 7px 8mm;
    padding:0;
}

.pat-list li{
    margin:2px 0;
    padding-left:1px;
    text-align:left;
}

.pat-checklist{
    font-weight:700;
    margin:3px 0 8px;
}

.pat-checklist .pat-field{
    min-width:68mm;
}

.pat-output{
    margin:3px 0 0;
    text-align:justify;
}

.pat-output-list{
    margin-top:2px;
    margin-bottom:7px;
}

.pat-fee{
    text-align:justify;
    margin-top:7px;
}

.pat-confirmation{
    text-align:justify;
    margin-top:7px;
}

/* ---------- Inline fields ---------- */

.pat-field{
    display:inline-block;
    min-width:52mm;
    border-bottom:1px solid #111;
    padding:0 2px 1px;
    font-weight:400;
    vertical-align:baseline;
}

.pat-field-short{
    min-width:28mm;
}

/* ---------- Signatures ---------- */

.pat-signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:32mm;
    margin-top:16px;
}

.pat-signature-title{
    font-weight:700;
    margin-bottom:5px;
}

.pat-signature-line{
    margin:4px 0;
    white-space:nowrap;
}

/* ---------- Permanent footer ---------- */

.pat-document-footer{
    position:absolute !important;
    left:12mm;
    right:12mm;
    bottom:4mm;
    z-index:10 !important;
    text-align:center;
    background:#fff;
    color:#111;
    padding-top:2mm;
    box-sizing:border-box;
}

.pat-footer-title{
    font-size:12px;
    line-height:1.1;
    font-weight:700;
    margin:0;
}

.pat-footer-page{
    font-size:10px;
    line-height:1.1;
    margin:1mm 0 0;
}

/* Keep old PAT footer classes harmless if referenced elsewhere. */
.pat-bottom,
.pat-page-number{
    display:none !important;
}

/* ---------- Screen ---------- */

@media screen{
    .pat-template-paper{
        box-shadow:0 0 8px rgba(0,0,0,.18);
    }
}

/* ---------- Print ---------- */

@media print{

    html,
    body{
        width:210mm !important;
        min-width:210mm !important;
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
    }

    .pat-template-paper{
        width:210mm !important;
        height:297mm !important;
        min-width:210mm !important;
        min-height:297mm !important;
        max-width:none !important;
        max-height:297mm !important;
        margin:0 !important;
        padding:8mm 12mm 6mm !important;
        box-shadow:none !important;
        overflow:hidden !important;
        page-break-before:avoid !important;
        page-break-after:avoid !important;
        page-break-inside:avoid !important;
        break-after:avoid !important;
    }

    .pat-template-watermark{
        display:block !important;
    }

    .pat-document-footer{
        position:absolute !important;
        bottom:4mm !important;
        display:block !important;
    }

    .pat-template-paper,
    .pat-template-paper *{
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
    }
}
</style>


<div class="pat-template-paper">

    <!-- =====================================================
         NLETA LOGO WATERMARK
         Same watermark treatment as SAT / SCI
    ====================================================== -->

    <div class="pat-template-watermark" aria-hidden="true">
        <img
            src="../assets/images/nleta-logo.jpeg"
            alt=""
        >
    </div>


    <div class="pat-ref">
        <?= h($serviceRef) ?>
    </div>


    <div class="pat-main-title">
        SERVICE ACTIVATION FORM (SAF)
    </div>


    <div class="pat-sub-title">
        <?= h($serviceTitle) ?>
    </div>


    <!-- =====================================================
         PAT HEADER ONLY — SAFE EDIT AREA
         Same 2-column structure as SAT / SCI
    ====================================================== -->

    <div class="pat-header-block">

        <div class="pat-header-row">

            <div class="pat-header-cell">

                <span class="pat-header-label">
                    PSGA / Contract No.:
                </span>

                <span class="pat-header-field">
                    <?= h($templatePsgaNo) ?>
                </span>

            </div>


            <div class="pat-header-cell">

                <span class="pat-header-label">
                    SAF No.:
                </span>

                <span class="pat-header-field">
                    <?= h($templateSafNo) ?>
                </span>

            </div>

        </div>


        <div class="pat-header-row">

            <div class="pat-header-cell">

                <span class="pat-header-label">
                    Date of Activation:
                </span>

                <span class="pat-header-field">
                    <?= h($templateActivationDate) ?>
                </span>

            </div>

        </div>

    </div>


    <!-- =====================================================
         END PAT HEADER ONLY
    ====================================================== -->


    <p>
        This Service Activation Form is an integral part of the above-mentioned
        Professional Service &amp; Governance Agreement (PSGA / Contract) and
        shall be read together with it. All Client, Site, Asset, Stakeholder,
        General Terms, Responsibilities and Legal Conditions already recorded
        in the PSGA shall remain applicable and shall not be repeated in this Form.
    </p>


    <div class="pat-section">

        1. SERVICE ACTIVATION -

        <span class="pat-normal">
            The following service is hereby activated under the above Contract:
        </span>

    </div>


    <div class="pat-service-row">

        Activated Service:

        <span class="pat-field">
            <?= h($templateServiceName) ?>
        </span>

    </div>


    <div class="pat-service-row">

        Asset Registration / Unique ID:

        <span class="pat-field">
            <?= h($templateAssetUniqueId) ?>
        </span>

    </div>


    <div class="pat-service-row">

        Proposed Inspection / Service Date:

        <span class="pat-field">
            <?= h($templateInspectionDate) ?>
        </span>

    </div>


    <div class="pat-section">

        2. ACTIVATED SERVICE SCOPE -

        <span class="pat-normal">
            The PAT / Safety Inspection service shall cover, as applicable
            to the selected assignment:
        </span>

    </div>


    <ul class="pat-list">

        <li>
            Safety and operational condition review
        </li>

        <li>
            Visual and functional verification of relevant safety features
        </li>

        <li>
            Review of available technical / maintenance records
        </li>

        <li>
            Identification and recording of observed safety or compliance concerns
        </li>

        <li>
            Evidence-based observations wherever applicable
        </li>

        <li>
            Recommendations for rectification / further technical review,
            wherever required
        </li>

    </ul>


    <div class="pat-checklist">

        Applicable Service Documentation / Reference:

        <span class="pat-field">
            <?= h($templateChecklist) ?>
        </span>

    </div>


    <div class="pat-section">

        3. SERVICE OUTPUT -

        <span class="pat-normal">
            Upon completion of the activated service, NLETA shall provide
            the applicable:
        </span>

    </div>


    <div class="pat-output">

        <strong>PAT / Safety Inspection Report</strong>
        - The applicable output may include:

    </div>


    <ul class="pat-list pat-output-list">

        <li>
            Service observations / findings
        </li>

        <li>
            Supporting evidence / records, where applicable
        </li>

        <li>
            Identified concerns or assessment results
        </li>

        <li>
            Recommendations / corrective actions, where applicable
        </li>

        <li>
            Service status / outcome at the time of assessment
        </li>

    </ul>


    <div class="pat-section pat-fee">

        4. APPLICABLE FEE -

        <span class="pat-normal">
            As per the accepted commercial proposal / NLETA Technical Services
            Fee Reference – Annexure–A. Any additional visit, special testing,
            equipment requirement or change in the approved service scope shall
            require prior discussion and approval.
        </span>

    </div>


    <div class="pat-section pat-confirmation">

        5. SERVICE-SPECIFIC CONFIRMATION -

        <span class="pat-normal">
            By acceptance of this SAF, the parties confirm that:
        </span>

    </div>


    <ul class="pat-list">

        <li>
            The above <?= h($templateServiceName) ?> service is activated
            under the referenced PSGA / Contract.
        </li>

        <li>
            The applicable service scope and deliverable are understood.
        </li>

        <li>
            All general contractual terms and stakeholder responsibilities
            shall remain governed by the PSGA.
        </li>

        <li>
            No additional contractual obligation is created through this SAF
            beyond the specifically activated service.
        </li>

    </ul>


    <!-- =====================================================
         SIGNATURES
    ====================================================== -->

    <div class="pat-signatures">

        <div>

            <div class="pat-signature-title">
                For Client / Owner / RWA
            </div>

            <div class="pat-signature-line">
                Name: ___________________________
            </div>

            <div class="pat-signature-line">
                Designation: _____________________
            </div>

            <div class="pat-signature-line">
                Signature: _______________________
            </div>

            <div class="pat-signature-line">
                Date: ____________________________
            </div>

        </div>


        <div>

            <div class="pat-signature-title">
                For NLETA (Authorized Signatory)
            </div>

            <div class="pat-signature-line">
                Name: ___________________________
            </div>

            <div class="pat-signature-line">
                Employee ID: ____________________
            </div>

            <div class="pat-signature-line">
                Signature: _______________________
            </div>

            <div class="pat-signature-line">
                Date: ____________________________
            </div>

        </div>

    </div>


    <!-- =====================================================
         PERMANENT FOOTER
    ====================================================== -->

    <div class="pat-document-footer">

        <div class="pat-footer-title">
            “NLETA – Safety Through System”
        </div>

        <div class="pat-footer-page">
            Page 1 of 1
        </div>

    </div>


</div>
