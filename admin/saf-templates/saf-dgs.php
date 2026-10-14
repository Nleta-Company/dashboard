<?php
/* =========================================================
   NLETA — SAF-DGS TEMPLATE
   ---------------------------------------------------------
   Digital Governance Support

   SHARED TEMPLATE:
       Admin SAF View
       Owner SAF View

   IMPORTANT:
   ---------------------------------------------------------
   Any document-formatting change made in this file
   automatically applies to BOTH Admin and Owner SAF views.

   DGS is a governance service and does not create an
   inspection/checklist/Inspector workflow.
========================================================= */

$templateSaf = (
    isset($saf)
    &&
    is_array($saf)
)
    ? $saf
    : [];


/* =========================================================
   HELPERS
========================================================= */

function safDgsH($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


function safDgsDate($value): string
{
    if (empty($value)) {

        return '____ / ____ / ______';
    }

    try {

        return (
            new DateTime(
                (string)$value,
                new DateTimeZone('Asia/Kolkata')
            )
        )->format(
            'd / m / Y'
        );

    } catch (Throwable $e) {

        return '____ / ____ / ______';
    }
}


/* =========================================================
   CONTRACT DATA
========================================================= */

$templatePsgaNo =
    trim(
        (string)(
            $templateSaf['psga_no']
            ?? ''
        )
    );

$templatePsgaNo =
    $templatePsgaNo !== ''
        ? $templatePsgaNo
        : '________________';


$templateSafNo =
    trim(
        (string)(
            $templateSaf['saf_no']
            ?? ''
        )
    );

$templateSafNo =
    $templateSafNo !== ''
        ? $templateSafNo
        : '________________';


$templateActivationDate =
    safDgsDate(
        $templateSaf['activation_date']
        ?? null
    );


/* =========================================================
   ASSET ID
========================================================= */

$templateAssetId =
    trim(
        (string)(
            $templateSaf['display_asset_id']
            ?? ''
        )
    );


/*
 * Legacy fallback:
 * If display_asset_id is unavailable,
 * fetch it from assets table.
 */

if (
    $templateAssetId === ''
    &&
    !empty($templateSaf['asset_id'])
    &&
    isset($conn)
    &&
    $conn instanceof mysqli
) {

    $assetStmt = $conn->prepare("
        SELECT
            display_asset_id
        FROM assets
        WHERE id = ?
        LIMIT 1
    ");

    if ($assetStmt) {

        $assetId =
            (int)$templateSaf['asset_id'];

        $assetStmt->bind_param(
            'i',
            $assetId
        );

        if ($assetStmt->execute()) {

            $assetRow =
                $assetStmt
                    ->get_result()
                    ->fetch_assoc();

            if ($assetRow) {

                $templateAssetId =
                    trim(
                        (string)(
                            $assetRow[
                                'display_asset_id'
                            ]
                            ??
                            ''
                        )
                    );
            }
        }

        $assetStmt->close();
    }
}


if ($templateAssetId === '') {

    $templateAssetId =
        '________________';
}


/* =========================================================
   DASHBOARD / ACCOUNT REFERENCE
========================================================= */

$templateDashboardRef =
    trim(
        (string)(
            $templateSaf[
                'dashboard_account_reference'
            ]
            ?? ''
        )
    );


if ($templateDashboardRef === '') {

    $templateDashboardRef =
        '__________________________';
}

?>

<style>

/* =========================================================
   GLOBAL DGS SAF FONT
   ---------------------------------------------------------
   Arial everywhere
========================================================= */

.saf-dgs-paper,
.saf-dgs-paper * {

    font-family:
        Arial,
        Helvetica,
        sans-serif;

}


/* =========================================================
   DGS SAF PAPER — SCREEN
========================================================= */

.saf-dgs-paper {

    width:210mm;

    min-height:297mm;

    height:auto;

    margin:0 auto;

    padding:
        15mm
        16mm
        13mm;

    box-sizing:border-box;

    background:#fff;

    color:#111;

    font-family:
        Arial,
        Helvetica,
        sans-serif;

    font-size:10.5pt;

    line-height:1.42;

    position:relative;

    /*
     * Allows padding/borders to behave correctly
     * when the element is fragmented across pages.
     */
    -webkit-box-decoration-break:clone;

    box-decoration-break:clone;
}


/* =========================================================
   DOCUMENT REFERENCE
========================================================= */

.saf-dgs-ref {

    font-size:10pt;

    font-weight:700;

    margin-bottom:7px;

}


/* =========================================================
   TITLE
========================================================= */

.saf-dgs-title {

    text-align:center;

    font-size:16pt;

    font-weight:800;

    margin:
        3px 0;

}


.saf-dgs-subtitle {

    text-align:center;

    font-size:13pt;

    font-weight:700;

    margin-bottom:14px;

}


/* =========================================================
   SECTION HEADINGS
========================================================= */

.saf-dgs-heading {

    font-size:10.5pt;

    font-weight:800;

    margin:
        9px 0 4px;

    line-height:1.25;

}


/* =========================================================
   CONTRACT BOX
========================================================= */

.saf-dgs-contract {

    border:
        1px solid #222;

    padding:
        7px 9px;

    margin-bottom:10px;

    box-sizing:border-box;

}


.saf-dgs-contract-row {

    display:flex;

    gap:8px;

    margin:2px 0;

    flex-wrap:wrap;

    align-items:flex-end;

}


.saf-dgs-label {

    font-weight:700;

}


.saf-dgs-field {

    border-bottom:
        1px solid #333;

    min-width:125px;

    display:inline-block;

    padding:
        0 3px;

    min-height:18px;

    vertical-align:bottom;

    overflow-wrap:anywhere;

    word-break:break-word;

}


/* =========================================================
   PARAGRAPHS
========================================================= */

.saf-dgs-paper p {

    margin:
        5px 0 8px;

    text-align:justify;

    line-height:1.42;

}


/* =========================================================
   LIST
========================================================= */

.saf-dgs-list {

    margin:
        4px 0 8px 20px;

    padding:0;

}


.saf-dgs-list li {

    margin:
        2px 0;

    line-height:1.38;

}


/* =========================================================
   PROFESSIONAL SIGNATURE SECTION
========================================================= */

.saf-dgs-signatures {

    display:grid;

    grid-template-columns:
        1fr 1fr;

    gap:14mm;

    margin-top:18mm;

    width:100%;

    box-sizing:border-box;

    break-inside:avoid;

    page-break-inside:avoid;

}


.saf-dgs-signature {

    width:100%;

    min-width:0;

    border:
        1px solid #555;

    padding:
        10px 12px 12px;

    box-sizing:border-box;

    background:#fff;

    break-inside:avoid;

    page-break-inside:avoid;

}


.saf-dgs-signature-title {

    font-size:10.5pt;

    font-weight:800;

    text-align:center;

    padding-bottom:8px;

    margin-bottom:10px;

    border-bottom:
        1px solid #777;

}


.saf-dgs-signature-row {

    display:grid;

    grid-template-columns:
        82px 1fr;

    align-items:end;

    min-height:25px;

    margin-bottom:7px;

    column-gap:7px;

}


.saf-dgs-signature-label {

    font-size:9.5pt;

    font-weight:700;

    white-space:nowrap;

}


.saf-dgs-signature-field {

    border-bottom:
        1px solid #333;

    min-height:18px;

    display:block;

    width:100%;

}


.saf-dgs-signature-note {

    font-size:8.5pt;

    color:#555;

    margin-top:7px;

    text-align:left;

}


/* =========================================================
   DOCUMENT FOOTER
   ---------------------------------------------------------
   This is OUR SAF footer.

   It is intentionally separate from the browser's
   generated print footer.

   Browser URL/date/page number are NOT controlled here.
========================================================= */

.saf-dgs-footer {

    width:100%;

    margin-top:16mm;

    padding-top:4mm;

    border-top:
        0.7px solid #777;

    text-align:center;

    font-size:9pt;

    font-weight:700;

    line-height:1.3;

    box-sizing:border-box;

    break-inside:avoid;

    page-break-inside:avoid;

}


/* =========================================================
   FOOTER SUBLINE
========================================================= */

.saf-dgs-footer-sub {

    display:block;

    margin-top:2px;

    font-size:8pt;

    font-weight:400;

}


/* =========================================================
   SCREEN
========================================================= */

@media screen {

    .saf-dgs-paper {

        box-shadow:
            0 3px 16px
            rgba(0,0,0,.12);

    }

}


/* =========================================================
   TABLET / MOBILE
========================================================= */

@media screen and (max-width:900px) {

    /*
     * Keep A4 width.
     * Parent SAF view already provides horizontal
     * scrolling.
     */

    .saf-dgs-paper {

        width:210mm;

        min-width:210mm;

        min-height:297mm;

        height:auto;

        padding:
            15mm
            16mm
            13mm;

        font-size:10.5pt;

    }


    .saf-dgs-signatures {

        grid-template-columns:
            1fr 1fr;

        gap:10mm;

    }

}


/* =========================================================
   PRINT PAGE SETUP
   ---------------------------------------------------------
   IMPORTANT:

   Browser-generated URL/date/page number shown in the
   screenshot is controlled by Chrome Print Settings.

   Turn OFF:
       More settings
       -> Headers and footers

   Our own SAF footer remains in the document.
========================================================= */

@page {

    size:A4 portrait;

    /*
     * Increased top margin so content on page 2+
     * does not start too close to the browser print header.

     * TOP    : 20mm
     * RIGHT  : 16mm
     * BOTTOM : 15mm
     * LEFT   : 16mm
     */

    margin:
        20mm
        16mm
        15mm
        16mm;

}


/* =========================================================
   PRINT
========================================================= */

@media print {

    /* -----------------------------------------------------
       ROOT
    ----------------------------------------------------- */

    html,
    body {

        width:auto !important;

        height:auto !important;

        min-height:0 !important;

        margin:0 !important;

        padding:0 !important;

        background:#fff !important;

        overflow:visible !important;

        font-family:
            Arial,
            Helvetica,
            sans-serif !important;

    }


    /* -----------------------------------------------------
       DOCUMENT
    ----------------------------------------------------- */

    .saf-dgs-paper {

        width:auto !important;

        max-width:none !important;

        min-width:0 !important;

        height:auto !important;

        min-height:0 !important;

        max-height:none !important;

        margin:0 !important;

        /*
         * @page supplies the repeated page spacing.
         */
        padding:0 !important;

        box-sizing:border-box !important;

        background:#fff !important;

        box-shadow:none !important;

        overflow:visible !important;

        position:relative !important;

        font-family:
            Arial,
            Helvetica,
            sans-serif !important;

        font-size:10.5pt !important;

        line-height:1.42 !important;

        page-break-before:auto !important;

        page-break-after:auto !important;

        page-break-inside:auto !important;

        break-before:auto !important;

        break-after:auto !important;

        break-inside:auto !important;

    }


    /* -----------------------------------------------------
       ALL CHILDREN ARIAL
    ----------------------------------------------------- */

    .saf-dgs-paper,
    .saf-dgs-paper * {

        font-family:
            Arial,
            Helvetica,
            sans-serif !important;

    }


    /* -----------------------------------------------------
       REFERENCE / TITLE
    ----------------------------------------------------- */

    .saf-dgs-ref {

        font-size:10pt !important;

    }


    .saf-dgs-title {

        font-size:16pt !important;

    }


    .saf-dgs-subtitle {

        font-size:13pt !important;

    }


    /* -----------------------------------------------------
       HEADINGS
       Keep heading with following content.
    ----------------------------------------------------- */

    .saf-dgs-heading {

        break-after:avoid !important;

        page-break-after:avoid !important;

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    /* -----------------------------------------------------
       CONTRACT BOX
    ----------------------------------------------------- */

    .saf-dgs-contract {

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    /* -----------------------------------------------------
       LIST
    ----------------------------------------------------- */

    .saf-dgs-list {

        break-inside:auto !important;

        page-break-inside:auto !important;

    }


    .saf-dgs-list li {

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    /* -----------------------------------------------------
       PARAGRAPHS
    ----------------------------------------------------- */

    .saf-dgs-paper p {

        orphans:3 !important;

        widows:3 !important;

        break-inside:auto !important;

        page-break-inside:auto !important;

    }


    /* -----------------------------------------------------
       PROFESSIONAL SIGNATURES
    ----------------------------------------------------- */

    .saf-dgs-signatures {

        display:grid !important;

        grid-template-columns:
            1fr 1fr !important;

        gap:14mm !important;

        margin-top:18mm !important;

        width:100% !important;

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    .saf-dgs-signature {

        width:100% !important;

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    .saf-dgs-signature-title {

        break-after:avoid !important;

        page-break-after:avoid !important;

    }


    /* -----------------------------------------------------
       FOOTER
       -----------------------------------------------------
       Normal document flow.

       No absolute positioning.
       No bottom:7mm.
       No overlap.
    ----------------------------------------------------- */

    .saf-dgs-footer {

        position:static !important;

        left:auto !important;

        right:auto !important;

        bottom:auto !important;

        width:100% !important;

        margin-top:16mm !important;

        padding-top:4mm !important;

        border-top:
            0.7px solid #777 !important;

        text-align:center !important;

        font-size:9pt !important;

        break-inside:avoid !important;

        page-break-inside:avoid !important;

    }


    /* -----------------------------------------------------
       PREVENT CLIPPING
    ----------------------------------------------------- */

    .saf-dgs-paper,
    .saf-dgs-paper * {

        max-height:none !important;

    }


    /* -----------------------------------------------------
       PRINT COLOURS
    ----------------------------------------------------- */

    * {

        -webkit-print-color-adjust:exact !important;

        print-color-adjust:exact !important;

    }

}

</style>


<!-- =======================================================
     DGS SAF DOCUMENT
======================================================== -->

<div class="saf-dgs-paper">


    <!-- ===================================================
         DOCUMENT REFERENCE
    ==================================================== -->

    <div class="saf-dgs-ref">

        NLETA/SAF-DGS/2026 (Rev.1)

    </div>


    <!-- ===================================================
         TITLE
    ==================================================== -->

    <div class="saf-dgs-title">

        SERVICE ACTIVATION FORM (SAF)

    </div>


    <div class="saf-dgs-subtitle">

        “DGS (DIGITAL GOVERNANCE SUPPORT)”

    </div>


    <!-- ===================================================
         CONTRACT REFERENCE
    ==================================================== -->

    <div class="saf-dgs-heading">

        CONTRACT REFERENCE:

    </div>


    <div class="saf-dgs-contract">

        <div class="saf-dgs-contract-row">

            <span class="saf-dgs-label">

                PSGA / Contract No.:

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templatePsgaNo)?>

            </span>


            <span class="saf-dgs-label">

                SAF No.:

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templateSafNo)?>

            </span>


            <span class="saf-dgs-label">

                Date of Activation:

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templateActivationDate)?>

            </span>

        </div>

    </div>


    <!-- ===================================================
         INTRODUCTION
    ==================================================== -->

    <p>

        This Service Activation Form is an integral part of the
        above-mentioned Professional Service &amp; Governance
        Agreement (PSGA / Contract) and shall be read together
        with it. All Client, Site, Asset, Stakeholder, General
        Terms, Responsibilities and Legal Conditions already
        recorded in the PSGA shall remain applicable and shall
        not be repeated in this Form.

    </p>


    <!-- ===================================================
         1. SERVICE ACTIVATION
    ==================================================== -->

    <div class="saf-dgs-heading">

        1. SERVICE ACTIVATION-

    </div>


    <p>

        The following service is hereby activated under the
        above Contract:

    </p>


    <div class="saf-dgs-contract">

        <div class="saf-dgs-contract-row">

            <span class="saf-dgs-label">

                Digital Governance Activation Date:

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templateActivationDate)?>

            </span>

        </div>


        <div class="saf-dgs-contract-row">

            <span class="saf-dgs-label">

                Asset / Governance Reference:

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templateAssetId)?>

            </span>

        </div>


        <div class="saf-dgs-contract-row">

            <span class="saf-dgs-label">

                Dashboard / Account Reference (if applicable):

            </span>

            <span class="saf-dgs-field">

                <?=safDgsH($templateDashboardRef)?>

            </span>

        </div>

    </div>


    <!-- ===================================================
         2. ACTIVATED SERVICE SCOPE
    ==================================================== -->

    <div class="saf-dgs-heading">

        2. ACTIVATED SERVICE SCOPE-

    </div>


    <p>

        Digital Governance Support may include, as applicable
        to the selected assignment:

    </p>


    <ul class="saf-dgs-list">

        <li>
            Digital asset profiling and unique asset identification
        </li>

        <li>
            State government registration / compliance status tracking
        </li>

        <li>
            NOC / renewal status tracking, wherever applicable
        </li>

        <li>
            Insurance status tracking
        </li>

        <li>
            Maintenance schedule and maintenance record tracking
        </li>

        <li>
            AMC / service provider details and contact management
        </li>

        <li>
            Callback generation and tracking
        </li>

        <li>
            Callback acceptance and closure tracking
        </li>

        <li>
            Maintenance reminder / scheduled activity alerts
        </li>

        <li>
            Document and record availability
        </li>

        <li>
            Authorized representative notification / governance alerts
        </li>

        <li>
            Governance dashboard and audit-trail support
        </li>

        <li>
            Monthly governance reporting and analytics, wherever applicable
        </li>

    </ul>


    <p>

        <strong>
            Applicable Digital Governance Checklist / Reference:
        </strong>

        __________________________

    </p>


    <!-- ===================================================
         3. SERVICE OUTPUT
    ==================================================== -->

    <div class="saf-dgs-heading">

        3. SERVICE OUTPUT-

    </div>


    <p>

        Upon activation and implementation, the applicable
        governance system may provide:

    </p>


    <ul class="saf-dgs-list">

        <li>
            Asset-wise digital records
        </li>

        <li>
            Compliance / renewal status visibility
        </li>

        <li>
            Maintenance tracking
        </li>

        <li>
            Callback tracking and closure status
        </li>

        <li>
            Reminder / alert support
        </li>

        <li>
            Governance communication records
        </li>

        <li>
            Monthly failure / callback data, wherever applicable
        </li>

        <li>
            Governance reports / analytics
        </li>

    </ul>


    <!-- ===================================================
         4. IMPLEMENTATION & RESPONSIBILITY
    ==================================================== -->

    <div class="saf-dgs-heading">

        4. IMPLEMENTATION &amp; RESPONSIBILITY-

    </div>


    <p>

        Digital Governance Support is intended to improve
        visibility, documentation, accountability and timely
        follow-up. The digital system does not replace:

    </p>


    <ul class="saf-dgs-list">

        <li>
            statutory responsibility,
        </li>

        <li>
            maintenance execution,
        </li>

        <li>
            operational supervision,
        </li>

        <li>
            emergency response responsibility, or
        </li>

        <li>
            the Client's / AMC's obligation to act within their
            respective scope.
        </li>

    </ul>


    <p>

        Information entered into the system shall be provided /
        verified by the respective responsible stakeholder.

    </p>


    <!-- ===================================================
         5. APPLICABLE FEE
    ==================================================== -->

    <div class="saf-dgs-heading">

        5. APPLICABLE FEE -

    </div>


    <p>

        As per the accepted commercial proposal / NLETA Safety
        &amp; Governance Services Fee Reference – Annexure–B.
        Any additional asset, customized dashboard requirement,
        additional integration, special reporting or change in
        the approved scope shall require prior discussion and
        approval.

    </p>


    <!-- ===================================================
         6. SERVICE-SPECIFIC CONFIRMATION
    ==================================================== -->

    <div class="saf-dgs-heading">

        6. SERVICE-SPECIFIC CONFIRMATION-

    </div>


    <p>

        By acceptance of this SAF, the parties confirm that:

    </p>


    <ul class="saf-dgs-list">

        <li>
            The above Digital Governance Support service is
            activated under the referenced PSGA / Contract.
        </li>

        <li>
            The applicable digital scope and governance outputs
            are understood.
        </li>

        <li>
            Digital alerts, reminders or system-generated
            notifications are governance-support mechanisms
            and do not constitute statutory certification or
            operational instructions unless specifically
            authorized.
        </li>

        <li>
            All general contractual terms and stakeholder
            responsibilities shall remain governed by the PSGA.
        </li>

        <li>
            No additional contractual obligation is created
            through this SAF beyond the specifically activated
            service.
        </li>

    </ul>


    <!-- ===================================================
         PROFESSIONAL SIGNATURE SECTION
    ==================================================== -->

    <div class="saf-dgs-signatures">


        <!-- =================================================
             CLIENT / OWNER / RWA
        ================================================== -->

        <div class="saf-dgs-signature">

            <div class="saf-dgs-signature-title">

                For Client / Owner / RWA

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Name:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Designation:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Signature:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Date:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-note">

                Authorized representative of the Client / Owner / RWA

            </div>

        </div>


        <!-- =================================================
             NLETA
        ================================================== -->

        <div class="saf-dgs-signature">

            <div class="saf-dgs-signature-title">

                For NLETA (Authorized Signatory)

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Name:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Designation:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Signature:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-row">

                <span class="saf-dgs-signature-label">
                    Date:
                </span>

                <span class="saf-dgs-signature-field"></span>

            </div>


            <div class="saf-dgs-signature-note">

                Authorized Signatory, NLETA

            </div>

        </div>


    </div>


    <!-- ===================================================
         DOCUMENT FOOTER
    ==================================================== -->

    <div class="saf-dgs-footer">

        “NLETA – Safety Through System”

        <span class="saf-dgs-footer-sub">
            Service Activation Form — Digital Governance Support
        </span>

    </div>


</div>