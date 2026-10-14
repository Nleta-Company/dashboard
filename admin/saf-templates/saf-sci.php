<?php
/* =========================================================
   NLETA — SAF-SCI TEMPLATE
   ---------------------------------------------------------
   Exact one-page A4 structure based on the supplied
   "SAF- Standard Compliance Inspection (SCI).docx".

   Loaded by admin/saf-view.php.
   Dynamic values are resolved safely here.
========================================================= */

$templateSaf = (isset($saf) && is_array($saf)) ? $saf : [];

$templatePsgaNo = trim((string)($templateSaf['psga_no'] ?? ''));
if ($templatePsgaNo === '') {
    $templatePsgaNo = '_________________________';
}

$templateSafNo = trim((string)($templateSaf['saf_no'] ?? ''));
if ($templateSafNo === '') {
    $templateSafNo = '______________________';
}

$templatePatReportNo = trim((string)($templateSaf['pat_report_no'] ?? ''));
if ($templatePatReportNo === '') {
    $templatePatReportNo = '';
}

$templateSatReportNo = trim((string)($templateSaf['sat_report_no'] ?? ''));
if ($templateSatReportNo === '') {
    $templateSatReportNo = '';
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
        $templateInspectionDate = '____ / ____ / _______';
    }
} else {
    $templateInspectionDate = '____ / ____ / _______';
}

$templateAssetUniqueId = trim(
    (string)($templateSaf['display_asset_id'] ?? '')
);

if ($templateAssetUniqueId === '') {
    $templateAssetId = (int)($templateSaf['asset_id'] ?? 0);

    if ($templateAssetId > 0 && isset($conn) && $conn instanceof mysqli) {
        $assetStmt = $conn->prepare("
            SELECT display_asset_id
            FROM assets
            WHERE id = ?
            LIMIT 1
        ");

        if ($assetStmt) {
            $assetStmt->bind_param('i', $templateAssetId);

            if ($assetStmt->execute()) {
                $assetRow = $assetStmt->get_result()->fetch_assoc();

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
    $templateAssetUniqueId = '________________';
}

$templateChecklist = trim(
    (string)($templateSaf['checklist_reference'] ?? '')
);

if ($templateChecklist === '') {
    $templateChecklist = '';
}

$serviceRef = 'NLETA/SAF-SCI/2026 (Rev.1)';
?>

<style>
/* =========================================================
   NLETA — SAF-SCI A4 DOCUMENT CSS
   ---------------------------------------------------------
   Exact A4 page: 210mm × 297mm
   Footer is permanently anchored to the bottom of the page.
========================================================= */

@page{
    size:A4 portrait;
    margin:0;
}

html,
body{
    margin:0;
    padding:0;
    background:#fff;
}

.sci-template-paper{
    width:210mm;
    height:297mm;
    min-width:210mm;
    min-height:297mm;
    max-width:none;
    max-height:297mm;
    margin:0 auto;
    padding:10mm 10mm 22mm 10mm;
    box-sizing:border-box;
    position:relative;
    overflow:hidden;
    background:#fff;
    color:#111;
    font-family:Arial, Helvetica, sans-serif;
    font-size:14px;
    line-height:1.25;
    page-break-after:always;
    break-after:page;
}

.sci-template-paper > *{
    position:relative;
    z-index:2;
}

.sci-template-watermark{
    position:absolute !important;
    z-index:1 !important;
    left:50%;
    top:51%;
    width:180mm;
    max-width:72%;
    transform:translate(-50%,-50%);
    opacity:.09;
    pointer-events:none;
}

.sci-template-watermark img{
    display:block;
    width:100%;
    height:auto;
}

.sci-template-watermark img{
    display:block;
    width:100%;
    height:auto;
}

.sci-ref{
    text-align:right;
    font-size:13.5px;
    margin:0 0 6px;
}

.sci-main-title{
    text-align:center;
    font-size:20px;
    line-height:1.1;
    font-weight:700;
    text-decoration:underline;
    margin:0;
}

.sci-sub-title{
    text-align:center;
    font-size:15.5px;
    line-height:1.12;
    font-weight:700;
    margin:3px 0 11px;
}

/* =========================================================
   >>> HEADER ONLY — EDIT POINT <<<
   ---------------------------------------------------------
   ONLY change values inside this block when adjusting:
   PSGA / Contract No.
   SAF No.
   PAT Report No.
   SAT Report No.

   DO NOT use .sci-field / .sci-cell / .sci-row here.
   These header-only classes are isolated so changes here
   cannot affect the rest of the SAF document.
========================================================= */

.sci-header-block{
    width:100%;
    margin:0 0 9px;
}

.sci-header-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:14mm;
    width:100%;
    align-items:center;
    margin:0 0 5px;
}

.sci-header-cell{
    display:grid;
    grid-template-columns:max-content 1fr;
    align-items:center;
    column-gap:2.5mm;
    min-width:0;
    font-weight:700;
    white-space:nowrap;
}

.sci-header-label{
    display:block;
    white-space:nowrap;
}

.sci-header-field{
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
   <<< END HEADER ONLY — EDIT POINT >>>
========================================================= */

.sci-template-paper p{
    margin:8px 0;
    text-align:justify;
}

.sci-prerequisite{
    font-weight:700;
    text-align:justify;
    margin:9px 0 10px;
}

.sci-section{
    margin-top:9px;
    margin-bottom:2px;
    text-align:justify;
    font-weight:700;
}

.sci-normal{
    font-weight:400;
}

.sci-activation{
    font-weight:700;
    margin:3px 0 9px;
    white-space:nowrap;
}

.sci-id-field{
    min-width:46mm;
}

.sci-date-field{
    min-width:40mm;
}

.sci-list{
    margin:2px 0 7px 8mm;
    padding:0;
}

.sci-list li{
    margin:3px 0;
    padding-left:1px;
    text-align:left;
}

.sci-checklist{
    font-weight:700;
    margin:3px 0 8px;
}

.sci-checklist-field{
    min-width:68mm;
}

.sci-output{
    margin:3px 0 0;
    text-align:justify;
}

.sci-fee,
.sci-confirmation{
    text-align:justify;
    margin-top:7px;
}

.sci-signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:28mm;
    margin-top:11px;
}

.sci-signature-title{
    font-weight:700;
    margin-bottom:4px;
}

.sci-signature-line{
    margin:3px 0;
    white-space:nowrap;
}

/* =========================================================
   PERMANENT FOOTER
   ---------------------------------------------------------
   Always stays at the bottom of the A4 page.
========================================================= */
.sci-document-footer{
    position:absolute !important;
    left:12mm;
    right:12mm;
    bottom:5mm;
    z-index:10 !important;
    text-align:center;
    background:#fff;
    color:#111;
    padding-top:2mm;
    box-sizing:border-box;
}


.sci-footer-title{
    font-size:12px;
    line-height:1.1;
    font-weight:700;
    margin:0;
}

.sci-footer-page{
    font-size:10px;
    line-height:1.1;
    margin:1mm 0 0;
}

/* Keep old class names harmless if referenced elsewhere. */
.sci-bottom,
.sci-page-number{
    display:none !important;
}

@media screen{
    .sci-template-paper{
        box-shadow:0 0 8px rgba(0,0,0,.18);
    }
}

@media print{
    html,
    body{
        width:210mm !important;
        min-width:210mm !important;
        margin:0 !important;
        padding:0 !important;
        background:#fff !important;
    }

    .sci-template-paper{
        width:210mm !important;
        height:297mm !important;
        min-width:210mm !important;
        min-height:297mm !important;
        max-width:210mm !important;
        max-height:297mm !important;
        margin:0 !important;
        padding:10mm 10mm 22mm 10mm !important;
        box-shadow:none !important;
        overflow:hidden !important;
        page-break-before:avoid !important;
        page-break-after:always !important;
        page-break-inside:avoid !important;
        break-after:page !important;
    }

    .sci-template-paper:last-child{
        page-break-after:auto !important;
        break-after:auto !important;
    }

    .sci-template-watermark{
        display:block !important;
    }

    .sci-document-footer{
        position:absolute !important;
        bottom:4mm !important;
        display:block !important;
    }

    .sci-template-paper,
    .sci-template-paper *{
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
    }
}
</style>

<div class="sci-template-paper">

    <!-- =====================================================
         PERMANENT NLETA LOGO WATERMARK
         -----------------------------------------------------
         Uses the existing project logo:
         assets/images/nleta-logo.jpeg

         The logo is decorative and does not affect document
         content or layout.
    ===================================================== -->
    <div class="sci-template-watermark" aria-hidden="true">
        <img
            src="../assets/images/nleta-logo.jpeg"
            alt=""
        >
    </div>

    <div class="sci-ref"><?= h($serviceRef) ?></div>

    <div class="sci-main-title">
        SERVICE ACTIVATION FORM (SAF)
    </div>

    <div class="sci-sub-title">
        “SCI (STANDARDS COMPLIANCE INSPECTION)”
    </div>

    <!-- =====================================================
         HEADER ONLY — SAFE EDIT AREA
         ===================================================== -->
    <div class="sci-header-block">

        <div class="sci-header-row">
            <div class="sci-header-cell">
                <span class="sci-header-label">PSGA / Contract No.:</span>
                <span class="sci-header-field"><?= h($templatePsgaNo) ?></span>
            </div>

            <div class="sci-header-cell">
                <span class="sci-header-label">SAF No.:</span>
                <span class="sci-header-field"><?= h($templateSafNo) ?></span>
            </div>
        </div>

        <div class="sci-header-row">
            <div class="sci-header-cell">
                <span class="sci-header-label">PAT Report No.:</span>
                <span class="sci-header-field"><?= h($templatePatReportNo) ?></span>
            </div>

            <div class="sci-header-cell">
                <span class="sci-header-label">SAT Report No.:</span>
                <span class="sci-header-field"><?= h($templateSatReportNo) ?></span>
            </div>
        </div>

    </div>
    <!-- =====================================================
         END HEADER ONLY — SAFE EDIT AREA
         ===================================================== -->

    <p>
        This Service Activation Form is an integral part of the above-mentioned
        Professional Service &amp; Governance Agreement (PSGA / Contract) and
        shall be read together with it. All Client, Site, Asset, Stakeholder,
        General Terms, Responsibilities and Legal Conditions already recorded
        in the PSGA shall remain applicable and shall not be repeated in this Form.
    </p>

    <div class="sci-prerequisite">
        MANDATORY PREREQUISITE -
        <span class="sci-normal">
            PAT and SAT are mandatory prerequisites for Standards Compliance
            Inspection. A valid PAT Inspection / Report Reference No. and SAT
            Inspection / Report Reference No. shall be available before commencement
            of the Standards Compliance Inspection. In the absence of either reference,
            SCI shall not be conducted / activated.
        </span>
    </div>

    <div class="sci-section">
        1. SERVICE ACTIVATION -
        <span class="sci-normal">
            The following service is hereby activated under the above Contract:
        </span>
    </div>

    <div class="sci-activation">
        Registration / Unique ID:
        <span class="sci-field sci-id-field">
            <?= h($templateAssetUniqueId) ?>
        </span>

        &nbsp;&nbsp;&nbsp;

        Inspection Date:
        <span class="sci-field sci-date-field">
            <?= h($templateInspectionDate) ?>
        </span>
    </div>

    <div class="sci-section">
        2. ACTIVATED SERVICE SCOPE -
        <span class="sci-normal">
            The Standards Compliance Inspection shall cover, as applicable
            to the selected assignment:
        </span>
    </div>

    <ul class="sci-list">
        <li>Review against applicable statutory / regulatory requirements</li>
        <li>Review against relevant IS / EN / applicable engineering standards</li>
        <li>Verification of applicable safety and quality requirements</li>
        <li>Review of available technical, maintenance and compliance records</li>
        <li>Review of observations identified through the applicable PAT and SAT</li>
        <li>Identification and recording of observed deviations / compliance concerns</li>
        <li>Evidence-based observations wherever applicable</li>
        <li>Recommendations for corrective action / further technical review, wherever required</li>
    </ul>

    <div class="sci-checklist">
        Applicable SCI Checklist No. / Reference:
        <span class="sci-field sci-checklist-field">
            <?= h($templateChecklist) ?>
        </span>
    </div>

    <div class="sci-section">
        3. SERVICE OUTPUT -
        <span class="sci-normal">
            Upon completion of the activated service, NLETA shall provide the applicable:
        </span>
    </div>

    <div class="sci-output">
        <strong>Standards Compliance Inspection Report</strong>,
        the report may include:
    </div>

    <ul class="sci-list">
        <li>Compliance observations</li>
        <li>Applicable standard / regulatory references</li>
        <li>Supporting evidence / records</li>
        <li>Identified deviations / concerns</li>
        <li>Recommendations / corrective actions</li>
        <li>Compliance status at the time of assessment</li>
    </ul>

    <div class="sci-section sci-fee">
        4. APPLICABLE FEE -
        <span class="sci-normal">
            As per the accepted commercial proposal / NLETA Technical Services Fee
            Reference – Annexure–A. Any additional visit, special testing, equipment
            requirement or change in the approved service scope shall require prior
            discussion and approval.
        </span>
    </div>

    <div class="sci-section sci-confirmation">
        5. SERVICE-SPECIFIC CONFIRMATION -
        <span class="sci-normal">
            By acceptance of this SAF, the parties confirm that:
        </span>
    </div>

    <ul class="sci-list">
        <li>The above SCI service is activated under the referenced PSGA / Contract.</li>
        <li>PAT and SAT have been completed / validly referenced as the mandatory prerequisites for SCI.</li>
        <li>The applicable service scope and deliverable are understood.</li>
        <li>All other terms and stakeholder responsibilities shall remain governed by the PSGA.</li>
        <li>No additional contractual obligation is created through this SAF beyond the specifically activated service.</li>
    </ul>

    <div class="sci-signatures">

        <div>
            <div class="sci-signature-title">
                For Client / Owner / RWA
            </div>
            <div class="sci-signature-line">
                Name: ___________________________
            </div>
            <div class="sci-signature-line">
                Designation: _____________________
            </div>
            <div class="sci-signature-line">
                Signature:
            </div>
            <div class="sci-signature-line">
                Date:
            </div>
        </div>

        <div>
            <div class="sci-signature-title">
                For NLETA (Authorized Signatory)
            </div>
            <div class="sci-signature-line">
                Name: ___________________________
            </div>
            <div class="sci-signature-line">
                Employee ID: ____________________
            </div>
            <div class="sci-signature-line">
                Signature:
            </div>
            <div class="sci-signature-line">
                Date:
            </div>
        </div>

    </div>

    <!-- =====================================================
         PERMANENT A4 FOOTER
         ===================================================== -->
    <div class="sci-document-footer">
        <div class="sci-footer-title">
            “NLETA – Safety Through System”
        </div>
        <div class="sci-footer-page">
            Page 1 of 1
        </div>
    </div>

</div>