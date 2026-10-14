<?php
/* =========================================================
   NLETA — SAF-SAT TEMPLATE
   ---------------------------------------------------------
   Exact document structure based on:
   "SAF- Quality Inspection (SAT) (1)(3).docx"

   This template is loaded by admin/saf-view.php.
   Document content is intentionally kept aligned with the
   approved Word format. Dynamic values come from the SAF record.
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

$templateInspectionDate = trim((string)($templateSaf['proposed_inspection_date'] ?? ''));
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

$templateAssetUniqueId = trim((string)($templateSaf['display_asset_id'] ?? ''));

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

$templateServiceName = trim(
    (string)($templateSaf['service_name'] ?? '')
);

if ($templateServiceName === '') {
    $templateServiceName = 'SAT';
}

/* Approved document reference */
$serviceRef = 'NLETA/SAF-SAT/2026 (Rev.1)';
?>

<style>
/* =========================================================
   EXACT SAT WORD-FORM LAYOUT
========================================================= */

.sat-template-paper{
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

.sat-template-paper > *{
    position:relative;
    z-index:2;
}

.sat-template-watermark{
    position:absolute !important;
    z-index:1 !important;
    left:50%;
    top:51%;
    width:172mm;
    max-width:78%;
    transform:translate(-50%,-50%);
    opacity:.09;
    pointer-events:none;
}

.sat-template-watermark img{
    display:block;
    width:100%;
    height:auto;
}

.sat-ref{
    text-align:right;
    font-size:13px;
    margin:0 0 2px;
}

.sat-main-title{
    text-align:center;
    font-size:19px;
    line-height:1.08;
    font-weight:700;
    text-decoration:underline;
    margin:0;
}

.sat-sub-title{
    text-align:center;
    font-size:15px;
    line-height:1.08;
    font-weight:700;
    margin:3px 0 10px;
}

/* =========================================================
   >>> SAT HEADER ONLY — SAFE EDIT POINT <<<
   ---------------------------------------------------------
   ONLY change values inside this block when adjusting:
   PSGA / Contract No.
   SAF No.
   PAT Checklist No.
   Date of Activation.

   Header-only classes are isolated from all other SAT fields.
   Do NOT change generic .sat-field here.
========================================================= */

.sat-header-block{
    width:100%;
    margin:0 0 10px;
}

.sat-header-row{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:14mm;
    width:100%;
    align-items:center;
    margin:0 0 5px;
}

.sat-header-cell{
    display:grid;
    grid-template-columns:max-content 1fr;
    align-items:center;
    column-gap:2.5mm;
    min-width:0;
    font-weight:700;
    white-space:nowrap;
}

.sat-header-label{
    display:block;
    white-space:nowrap;
}

.sat-header-field{
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
   <<< END SAT HEADER ONLY — SAFE EDIT POINT >>>
========================================================= */

.sat-template-paper p{
    margin:7px 0;
    text-align:justify;
}

.sat-prerequisite{
    font-weight:700;
    text-align:justify;
    margin:9px 0 10px;
}

.sat-section{
    margin-top:16px;
    margin-bottom:5px;
    text-align:justify;
    font-weight:700;
}

.sat-section .normal{
    font-weight:400;
}

.sat-service-row{
    font-weight:700;
    text-align:justify;
    margin:3px 0 8px;
}

.sat-service-row .sat-field{
    min-width:39mm;
}

.sat-scope-intro{
    margin-bottom:2px;
}

.sat-list{
    margin:2px 0 7px 8mm;
    padding:0;
}

.sat-list li{
    margin:2px 0;
    padding-left:1px;
    text-align:left;
}

.sat-checklist{
    font-weight:700;
    margin:3px 0 8px;
}

.sat-checklist .sat-field{
    min-width:68mm;
}

.sat-output{
    margin:3px 0 0;
    text-align:justify;
}

.sat-output-list{
    margin-top:2px;
    margin-bottom:7px;
}

.sat-fee{
    text-align:justify;
    margin-top:7px;
}

.sat-confirmation{
    text-align:justify;
    margin-top:7px;
}

.sat-signatures{
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:32mm;
    margin-top:16px;
}

.sat-signature-title{
    font-weight:700;
    margin-bottom:5px;
}

.sat-signature-line{
    margin:4px 0;
    white-space:nowrap;
}

.sat-bottom{
    text-align:center;
    font-weight:700;
    margin-top:11px;
}

.sat-page-number{
    text-align:center;
    font-size:10px;
    margin-top:5px;
}

/* ---------- Print ---------- */

@media print{
    .sat-template-paper{
        width:210mm !important;
        height:297mm !important;
        min-height:297mm !important;
        max-width:none !important;
        margin:0 !important;
        padding:8mm 12mm 6mm !important;
        box-shadow:none !important;
        overflow:hidden !important;
        page-break-before:avoid !important;
        page-break-after:avoid !important;
        page-break-inside:avoid !important;
    }

    .sat-template-watermark{
        display:block !important;
    }

    .sat-template-paper,
    .sat-template-paper *{
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
    }
}
</style>

<div class="sat-template-paper">

    <!-- =====================================================
         PERMANENT NLETA LOGO WATERMARK
         Logo: ../assets/images/nleta-logo.jpeg
         ===================================================== -->
    <div class="sat-template-watermark" aria-hidden="true">
        <img
            src="../assets/images/nleta-logo.jpeg"
            alt=""
        >
    </div>

    <div class="sat-ref"><?= h($serviceRef) ?></div>

    <div class="sat-main-title">
        SERVICE ACTIVATION FORM (SAF)
    </div>

    <div class="sat-sub-title">
        “SAT (SATISFACTORY ACCEPTANCE TEST / QUALITY INSPECTION)”
    </div>

    <!-- =====================================================
         SAT HEADER ONLY — SAFE EDIT AREA
         ===================================================== -->
    <div class="sat-header-block">

        <div class="sat-header-row">
            <div class="sat-header-cell">
                <span class="sat-header-label">PSGA / Contract No.:</span>
                <span class="sat-header-field"><?= h($templatePsgaNo) ?></span>
            </div>

            <div class="sat-header-cell">
                <span class="sat-header-label">SAF No.:</span>
                <span class="sat-header-field"><?= h($templateSafNo) ?></span>
            </div>
        </div>

        <div class="sat-header-row">
            <div class="sat-header-cell">
                <span class="sat-header-label">PAT Checklist No.:</span>
                <span class="sat-header-field"></span>
            </div>

            <div class="sat-header-cell">
                <span class="sat-header-label">Date of Activation:</span>
                <span class="sat-header-field"><?= h($templateActivationDate) ?></span>
            </div>
        </div>

    </div>
    <!-- =====================================================
         END SAT HEADER ONLY — SAFE EDIT AREA
         ===================================================== -->

    <p>
        This Service Activation Form is an integral part of the above-mentioned
        Professional Service &amp; Governance Agreement (PSGA / Contract) and
        shall be read together with it. All Client, Site, Asset, Stakeholder,
        General Terms, Responsibilities and Legal Conditions already recorded
        in the PSGA shall remain applicable and shall not be repeated in this Form.
    </p>

    <div class="sat-prerequisite">
        MANDATORY PREREQUISITE -
        <span style="font-weight:700;">
            PAT is mandatory prerequisites for SAT Inspection. A valid PAT
            Inspection / Report Reference No. shall be available before
            commencement of the SAT Inspection. In the absence of either
            reference, SAT shall not be conducted / activated.
        </span>
    </div>

    <div class="sat-section">
        1. SERVICE ACTIVATION -
        <span class="normal">
            The following service is hereby activated under the above Contract:
        </span>
    </div>

    <div class="sat-service-row">
        Registration / Unique ID:
        <span class="sat-field"><?= h($templateAssetUniqueId) ?></span>
        &nbsp;&nbsp;&nbsp;
        Inspection Date:
        <span class="sat-field"><?= h($templateInspectionDate) ?></span>
    </div>

    <div class="sat-section sat-scope-intro">
        2. ACTIVATED SERVICE SCOPE -
        <span class="normal">
            The SAT / Quality Inspection shall cover, as applicable to the
            selected assignment:
        </span>
    </div>

    <ul class="sat-list">
        <li>Verification of relevant installation / completion condition</li>
        <li>Functional and operational verification of applicable systems</li>
        <li>Review of available technical / completion records</li>
        <li>Verification of relevant safety and quality requirements</li>
        <li>Identification and recording of observed safety, quality or compliance concerns</li>
        <li>Evidence-based observations wherever applicable</li>
        <li>Recommendations for rectification / further technical review, wherever required</li>
    </ul>

    <div class="sat-checklist">
        Applicable SAT Checklist No. / Reference:
        <span class="sat-field"><?= h($templateChecklist) ?></span>
    </div>

    <div class="sat-section">
        3. SERVICE OUTPUT -
        <span class="normal">
            Upon completion of the activated service, NLETA shall provide the applicable:
        </span>
    </div>

    <div class="sat-output">
        <strong>SAT / Quality Inspection Report</strong>
        - The report may include:
    </div>

    <ul class="sat-list sat-output-list">
        <li>Inspection observations</li>
        <li>Supporting evidence / records</li>
        <li>Identified safety / quality concerns</li>
        <li>Recommendations / corrective actions</li>
        <li>Observation status at the time of assessment</li>
    </ul>

    <div class="sat-section sat-fee">
        4. APPLICABLE FEE-
        <span class="normal">
            As per the accepted commercial proposal / NLETA Technical Services
            Fee Reference – Annexure–A. Any additional visit, special testing,
            equipment requirement or change in the approved service scope shall
            require prior discussion and approval.
        </span>
    </div>

    <div class="sat-section sat-confirmation">
        5. SERVICE-SPECIFIC CONFIRMATION -
        <span class="normal">
            By acceptance of this SAF, the parties confirm that:
        </span>
    </div>

    <ul class="sat-list">
        <li>The above SAT service is activated under the referenced PSGA / Contract.</li>
        <li>The applicable service scope and deliverable are understood.</li>
        <li>All general contractual terms and stakeholder responsibilities shall remain governed by the PSGA.</li>
        <li>No additional contractual obligation is created through this SAF beyond the specifically activated service.</li>
    </ul>

    <div class="sat-signatures">

        <div>
            <div class="sat-signature-title">
                For Client / Owner / RWA
            </div>
            <div class="sat-signature-line">
                Name: ___________________________
            </div>
            <div class="sat-signature-line">
                Designation: _____________________
            </div>
            <div class="sat-signature-line">
                Signature:
            </div>
            <div class="sat-signature-line">
                Date:
            </div>
        </div>

        <div>
            <div class="sat-signature-title">
                For NLETA (Authorized Signatory)
            </div>
            <div class="sat-signature-line">
                Name: ___________________________
            </div>
            <div class="sat-signature-line">
                Employee ID: ____________________
            </div>
            <div class="sat-signature-line">
                Signature:
            </div>
            <div class="sat-signature-line">
                Date:
            </div>
        </div>

    </div>

    <div class="sat-bottom">
        “NLETA – Safety Through System”
    </div>

    <div class="sat-page-number">
        Page 1 of 1
    </div>

</div>
