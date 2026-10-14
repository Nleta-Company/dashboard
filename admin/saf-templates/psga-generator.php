<?php
/* =========================================================
   NLETA SHARED — PSGA DOCUMENT
   ---------------------------------------------------------
   ONE common PSGA document for BOTH:
      1. Admin side
      2. Owner side

   This file is presentation/rendering only.
   It does NOT create, update, delete, send, approve or reject
   any PSGA record.

   Admin usage:
       require_once('saf-templates/psga-generator.php');
       renderNletaPsga($p);

   Owner usage:
       require_once('../admin/saf-templates/psga-generator.php');
       renderNletaPsga($p);

   The same $p data structure is used on both sides, so the
   document cannot drift into two different formats.
========================================================= */

if (!function_exists('nletaPsgaH')) {
    function nletaPsgaH($v): string
    {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('h')) {
    function h($v): string
    {
        return nletaPsgaH($v);
    }
}

if (!function_exists('renderNletaPsga')) {
    function renderNletaPsga(array $p, bool $withContainer = true): void
    {
        /* =====================================================
           COMMON OWNER / ASSET FALLBACKS
        ===================================================== */
        $p['display_owner'] = trim(
            (string)($p['display_owner'] ?? $p['Owner_Name'] ?? '')
        );

        if ($p['display_owner'] === '') {
            $p['display_owner'] = trim(
                (string)($p['user_owner_name'] ?? '')
            );
        }

        if ($p['display_owner'] === '') {
            $p['display_owner'] = '________________________';
        }

        $p['asset_items'] = array_values(array_filter(
            array_map('trim', (array)($p['asset_items'] ?? [])),
            static fn($v) => $v !== ''
        ));

        if (!$p['asset_items']) {
            $fallback = trim(
                (string)(
                    $p['display_asset_id']
                    ?? $p['asset_name']
                    ?? ''
                )
            );

            if ($fallback !== '') {
                $p['asset_items'][] = $fallback;
            }
        }

        /* =====================================================
           COMMON APPROVED SERVICE LOGIC
           FSO-saved services first; legacy PSGA fallback second.
        ===================================================== */
        $approvedCodes = array_map(
            'strtoupper',
            array_map(
                'trim',
                (array)($p['approved_service_codes'] ?? [])
            )
        );

        $approvedNames = array_map(
            'strtolower',
            array_map(
                'trim',
                (array)($p['approved_service_names'] ?? [])
            )
        );

        $selected = strtolower(
            trim((string)($p['Selected_Services'] ?? ''))
        );

        $serviceList = [
            'PAT'  => 'PAT – Partial Acceptance Test / Safety Inspection',
            'SAT'  => 'SAT – Satisfactory Acceptance Test / Quality Inspection',
            'SCI'  => 'Standards Compliance Inspection',
            'TA'   => 'Technical Assessment',
            'DLA'  => 'Design Life Assessment',
            'MA'   => 'Modernization Advisory',
            'RA'   => 'Replacement Advisory',
            'UAP'  => 'User Awareness Program',
            'MOCK' => 'Mock Drill Support',
            'QRT'  => 'QRT Activation Support',
            'DG'   => 'Digital Governance Support',
            'IEGS' => 'Integrated Engineering Governance Support'
        ];

        $serviceAliases = [
            'PAT'  => ['pat', 'partial acceptance'],
            'SAT'  => ['sat', 'satisfactory acceptance'],
            'SCI'  => ['sci', 'standards compliance'],
            'TA'   => ['technical assessment'],
            'DLA'  => ['design life'],
            'MA'   => ['modernization'],
            'RA'   => ['replacement advisory'],
            'UAP'  => ['uap', 'user awareness'],
            'MOCK' => ['mock', 'drill'],
            'QRT'  => ['qrt'],
            'DG'   => ['dg', 'digital governance'],
            'IEGS' => ['iegs', 'integrated engineering']
        ];
?>
<style>
/* =========================================================
   SAME PSGA DOCUMENT CSS AS ADMIN
========================================================= */
.psga-page{padding:24px;max-width:1500px}
.psga-header,.psga-card,.psga-paper-wrap{
    background:#fff;border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,.07)
}
.psga-header{padding:22px;margin-bottom:20px}
.psga-card{overflow:hidden}
.psga-table{width:100%;border-collapse:collapse}
.psga-table th{background:#f8f9fa;font-size:12px;text-transform:uppercase;letter-spacing:.4px;padding:13px;border-bottom:1px solid #dee2e6}
.psga-table td{padding:13px;border-bottom:1px solid #edf0f2;vertical-align:middle}
.psga-no{font-weight:700;color:#0d6efd}
.status{display:inline-block;padding:6px 10px;border-radius:20px;font-size:12px;font-weight:600}
.s-owner{background:#fff3cd;color:#856404}
.s-review{background:#e2e3ff;color:#3730a3}.s-admin{background:#cff4fc;color:#055160}
.s-confirmed{background:#d1e7dd;color:#0f5132}
.s-rejected{background:#f8d7da;color:#842029}
.s-other{background:#e2e3e5;color:#41464b}
.stat{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.stat-label{font-size:12px;color:#6c757d}.stat-value{font-size:26px;font-weight:700}
.modal-info{background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
.psga-print-source{display:none}

/* =========================================================
   4-PAGE PSGA PAPER — EXACT ADMIN FORMAT
========================================================= */
.psga-paper-wrap{padding:20px;margin-top:20px}
.psga-page-paper{
    width:210mm;min-height:297mm;margin:0 auto 20px;background:#fff;
    box-sizing:border-box;padding:16mm 16mm 17mm;position:relative;
    border:1px solid #cfd5dc;color:#15191d;font-family:Arial,Helvetica,sans-serif;font-size:10.8pt;line-height:1.42;
    box-shadow:0 3px 14px rgba(0,0,0,.08)
}
.psga-page-paper h1{text-align:center;font-size:13pt;letter-spacing:.35px;margin:4px 0 5px;font-weight:800;color:#111}
.psga-page-paper h1 + .psga-doc-subtitle{display:block;text-align:center;font-size:9pt;letter-spacing:1px;color:#59636e;text-transform:uppercase;margin-bottom:13px}
.psga-doc-header{display:flex;align-items:center;gap:12px;border-bottom:2px solid #202a34;padding-bottom:8px;margin-bottom:10px;position:relative;z-index:2}
.psga-doc-logo{width:24mm;height:24mm;object-fit:contain;flex:0 0 auto}
.psga-doc-agency{flex:1}
.psga-doc-agency-name{font-size:14pt;font-weight:800;letter-spacing:.25px}
.psga-doc-tagline{font-size:8.5pt;color:#4b5560;margin-top:2px}
.psga-doc-meta{text-align:right;font-size:8.5pt;line-height:1.45;color:#3f4852;min-width:48mm}
.psga-doc-meta strong{color:#111}
.psga-doc-subtitle{display:block}
.psga-section-rule{border-top:1px solid #c8cfd6;margin:8px 0 10px}
.psga-page-paper h2{position:relative;z-index:1;font-size:11.8pt;margin:13px 0 5px;padding-bottom:3px;border-bottom:1px solid #dfe3e7;color:#202830}
.psga-page-paper p{margin:5px 0;position:relative;z-index:1}
.psga-page-paper > *:not(.psga-watermark){position:relative;z-index:1}
.psga-page-paper h2{position:relative;z-index:1;font-size:12.5pt;margin:14px 0 5px}
.psga-page-paper p{margin:5px 0;text-align:justify}
.psga-page-paper h2{line-height:1.22}
.psga-page-paper .center{text-align:center !important}
.psga-page-paper .footer{
    position:absolute;bottom:7mm;left:0;right:0;text-align:center;font-size:9pt
}

/* =========================================================
   FSO HEADER + FOOTER APPLIED TO PSGA
   Same visual identity/content as the current FSO renderer.
========================================================= */
.psga-doc-header.fso-style-header{
    display:flex;align-items:center;gap:8px;
    border-bottom:2px solid #111;padding-bottom:4px;margin-bottom:6px;
}
.fso-style-logo{width:70px;height:70px;object-fit:contain;flex:0 0 70px}
.fso-style-agency{flex:1;text-align:center;line-height:1.1}
.fso-style-agency-name{color:#e91e63;font-size:24px;font-weight:700;line-height:1.05}
.fso-style-tagline{font-size:12px;font-weight:700;margin-top:2px}
.fso-style-approval{font-size:10px;font-weight:700;margin-top:2px}
.fso-style-subtitle{font-size:9.5px;font-weight:600;margin-top:2px}
.fso-style-footer{
    position:absolute;left:0;right:0;bottom:5mm;
    border-top:2px solid #111;text-align:center;font-size:9.5px;
    line-height:1.3;padding-top:2px;background:#fff;z-index:20;
}
.psga-page-number{
    position:absolute;right:11mm;bottom:5.2mm;font-size:8.5px;
    line-height:1.2;background:#fff;padding-left:4px;z-index:21;
}

.psga-page-paper .rule{border-bottom:1px solid #111;display:inline;text-decoration:none;padding-bottom:1px}
/* Approved Services — ONE identical layout for Admin + Owner */
.psga-services{
    margin-top:6px;
    display:grid;
    grid-template-columns:1fr 1fr;
    column-gap:28px;
    row-gap:0;
    width:100%;
    box-sizing:border-box;
}

.psga-services-two-column{
    display:grid!important;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;
    column-gap:28px!important;
    row-gap:0!important;
    align-items:start;
}

.psga-service-column{
    min-width:0;
    width:100%;
}

.psga-service-option{
    display:flex;
    align-items:flex-start;
    gap:7px;
    min-width:0;
    margin:5px 0!important;
    line-height:1.32;
    break-inside:avoid;
    page-break-inside:avoid;
}

.psga-service-check{
    flex:0 0 14px;
    width:14px;
    font-size:13px;
    line-height:1.25;
    text-align:center;
}

.psga-service-option > span:last-child{
    flex:1 1 auto;
    min-width:0;
}

@media print{
    .psga-services-two-column{
        display:grid!important;
        grid-template-columns:minmax(0,1fr) minmax(0,1fr)!important;
        column-gap:28px!important;
        row-gap:0!important;
    }

    .psga-service-column{
        display:block!important;
    }

    .psga-service-option{
        display:flex!important;
        margin:4px 0!important;
        break-inside:avoid!important;
        page-break-inside:avoid!important;
    }
}
.psga-annexure-selection{
    display:flex;
    flex-wrap:wrap;
    gap:8px 24px;
    margin-top:12px;
    font-size:10.5pt;
}
@media(max-width:991.98px){
    .psga-services{grid-template-columns:1fr}
    .psga-services-two-column{grid-template-columns:1fr!important}
    .psga-signatures{grid-template-columns:1fr;gap:18px}
    .psga-doc-header{flex-wrap:wrap}
    .psga-doc-meta{margin-left:auto}
}
@media(max-width:575.98px){
    .psga-annexure-selection{display:block}
    .psga-annexure-selection span{display:block;margin-top:5px}
}
.psga-table-doc{width:100%;border-collapse:collapse;margin-top:8px}
.psga-table-doc th,.psga-table-doc td{border:1px solid #555;padding:6px 7px;vertical-align:top}.psga-table-doc th{background:#f2f4f6;font-weight:700}
.psga-table-doc th{text-align:center}
.psga-signatures{display:grid;grid-template-columns:1fr 1fr;gap:35px;margin-top:20px}
.psga-signatures .line{border-bottom:1px solid #111;min-height:28px;margin-bottom:8px}
.psga-watermark{
    position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);
    width:162mm;opacity:.075;pointer-events:none;z-index:0;text-align:center
}
.psga-watermark img{width:100%;height:auto;display:block;object-fit:contain}

@media(max-width:900px){
    .psga-page{padding:12px}
    .psga-paper-wrap{overflow:auto}
    .psga-page-paper{
        width:100%;min-height:auto;padding:24px 18px 45px;
        font-size:10.5pt;
    }
}

@media print{
    @page{size:A4 portrait;margin:0}
    body *{visibility:hidden!important}
    #psgaPrintArea,#psgaPrintArea *{visibility:visible!important}
    #psgaPrintArea{position:absolute;left:0;top:0;width:210mm;margin:0;padding:0}
    .psga-page-paper{
        width:210mm;height:297mm;min-height:297mm;margin:0;padding:18mm 15mm 16mm;
        border:0;border-radius:0;box-shadow:none;page-break-after:always;overflow:hidden
    }
    .psga-page-paper:last-child{page-break-after:auto}
    .no-print{display:none!important}
}

            /* FSO-style header/footer for PSGA print/PDF */
            .psga-doc-header.fso-style-header{display:flex !important;align-items:center !important;gap:8px !important;border-bottom:2px solid #111 !important;padding-bottom:4px !important;margin-bottom:6px !important;}
            .fso-style-logo{width:70px !important;height:70px !important;flex:0 0 70px !important;}
            .fso-style-agency{flex:1 !important;text-align:center !important;}
            .fso-style-agency-name{color:#e91e63 !important;font-size:24px !important;font-weight:700 !important;}
            .fso-style-tagline{font-size:12px !important;font-weight:700 !important;}
            .fso-style-approval{font-size:10px !important;font-weight:700 !important;}
            .fso-style-subtitle{font-size:9.5px !important;font-weight:600 !important;}
            .fso-style-footer{position:absolute !important;left:0 !important;right:0 !important;bottom:5mm !important;border-top:2px solid #111 !important;padding-top:2px !important;background:#fff !important;text-align:center !important;font-size:9.5px !important;line-height:1.3 !important;z-index:20 !important;}
            .psga-page-number{position:absolute !important;right:11mm !important;bottom:5.2mm !important;font-size:8.5px !important;background:#fff !important;z-index:21 !important;}

/* =========================================================
   PSGA APPROVED SERVICES — FINAL PRINT OVERRIDE
   ---------------------------------------------------------
   Browser print preview can report a narrow viewport. The
   responsive mobile rule above must NOT collapse Point 2.
   Always keep exactly 2 columns for A4 printing:
      LEFT  = services 1-6
      RIGHT = services 7-12
========================================================= */
@media print{
    .psga-page-paper .psga-services.psga-services-two-column{
        display:grid !important;
        grid-template-columns:minmax(0,1fr) minmax(0,1fr) !important;
        column-gap:28px !important;
        row-gap:0 !important;
        width:100% !important;
        box-sizing:border-box !important;
        align-items:start !important;
    }

    .psga-page-paper .psga-services.psga-services-two-column .psga-service-column{
        display:block !important;
        width:100% !important;
        min-width:0 !important;
    }

    .psga-page-paper .psga-services.psga-services-two-column .psga-service-option{
        display:flex !important;
        width:100% !important;
        margin:5px 0 !important;
        break-inside:avoid !important;
        page-break-inside:avoid !important;
    }
}

</style>
<?php if ($withContainer): ?>
<div id="psgaPrintArea" class="psga-paper-wrap">
<?php endif; ?>

        <!-- ================= PAGE 1 ================= -->
        <div class="psga-page-paper" id="psgaDoc<?= (int)($p['PSGA_ID'] ?? 0) ?>">

            <div class="psga-watermark">
                <img src="../assets/images/nleta-logo.jpeg" alt="">
            </div>

            <div class="psga-doc-header fso-style-header">

                <img
                    src="../assets/images/nleta-logo.jpeg"
                    alt="NLETA Logo"
                    class="psga-doc-logo fso-style-logo">

                <div class="fso-style-agency">

                    <div class="fso-style-agency-name">
                        National Lift Escalator Testing Agency
                    </div>

                    <div class="fso-style-tagline">
                        "Raising Standards, Developing Skills in Elevator Safety."
                    </div>

                    <div class="fso-style-approval">
                        A Lift &amp; Escalator Safety Enhancement Organization,
                        (Center Code – SIMTP/UP/77)
                    </div>

                    <div class="fso-style-subtitle">
                        Certified Third-Party Inspection • Testing • Technical Auditing •
                        Risk Analysis • Engineering Consultancy
                    </div>

                </div>

            </div>

            <h1>PROFESSIONAL SERVICE &amp; GOVERNANCE AGREEMENT (PSGA)</h1>

            <p>
                This Professional Service &amp; Governance Agreement (“Agreement”) is executed on
                <span class="rule">
                    <?=!empty($p['Contract_Date'])
                        ? h(formatISTDateOnly($p['Contract_Date']))
                        : '____/____/______'?>
                </span>,
                Under the Ref. No.
                <span class="rule"><?=h($p['PSGA_No'])?></span> between:
            </p>

            <p><strong>Client / Owner / RWA / Organization:</strong></p>

            <p>
                Name:
                <span class="rule"><?=h(
                    $p['display_association_name']
                    ?: ($p['organization_name'] ?? '')
                    ?: '________________________'
                )?></span>
            </p>

            <p>
                Address:
                <span class="rule"><?=h(
                    $p['display_association_address']
                    ?: ($p['organization_address'] ?? '')
                    ?: '________________________'
                )?></span>
            </p>

            <p>
                Authorized Representative:
                <span class="rule">
                    <?=h(
                        $p['display_representative']
                        ?: ($p['Authorized_Representative'] ?: ($p['display_owner'] ?: '________________________'))
                    )?>
                </span>
                
            </p>

            <p class="center"><strong>AND</strong></p>

            <p>
                <strong>National Lift Escalator Testing Agency (NLETA)</strong>,
                Herein after called “NLETA” Having Regd. Address at
                A-32, Commercial Complex, Govindpuram, Ghaziabad,
                Uttar Pradesh – 201013.
            </p>

            <p>
                NLETA is an independent engineering governance, technical advisory and safety support
                organization working for safer lift, escalator and moving walk systems through
                technical neutrality, documented governance and evidence-based recommendations.
            </p>

            <h2>Asset(s) Covered Under This PSGA</h2>

            <?php if (!empty($p['asset_items'])): ?>
                <?php foreach ($p['asset_items'] as $assetLabel): ?>
                    <p>☑ <?=h($assetLabel)?></p>
                <?php endforeach; ?>
            <?php else: ?>
                <p>—</p>
            <?php endif; ?>

            <h2>1. Purpose</h2>

            <p>
                This Agreement establishes a professional engineering governance relationship for the
                selected services related to lift, escalator and moving walk assets. The objective is timely
                compliance, documented communication, safety discipline, digital governance support and
                clear stakeholder responsibility.
            </p>

            <h2>2. Approved Services</h2>

            <p>The Client hereby selects the following service(s):</p>

            <?php
            $approvedCodes = array_map(
                'strtoupper',
                array_map('trim', (array)($p['approved_service_codes'] ?? []))
            );

            $approvedNames = array_map(
                'strtolower',
                array_map('trim', (array)($p['approved_service_names'] ?? []))
            );

            $selected = strtolower(
                trim((string)($p['Selected_Services'] ?? ''))
            );

            $serviceList = [
                'PAT'  => 'PAT – Partial Acceptance Test / Safety Inspection',
                'SAT'  => 'SAT – Satisfactory Acceptance Test / Quality Inspection',
                'SCI'  => 'SCI – Standards Compliance Inspection',
                'TA'   => 'TA – Technical Assessment',
                'DLA'  => 'DLA – Design Life Assessment',
                'MA'   => 'MA – Modernization Advisory',
                'RA'   => 'RA – Replacement Advisory',
                'UAP'  => 'UAP – User Awareness Program',
                'MOCK' => 'MOCK – Mock Drill Support',
                'QRT'  => 'QRT – Activation Support',
                'DG'   => 'DG – Digital Governance Support',
                'IEGS' => 'IEGS – Integrated Engineering Governance Support'
            ];

            $serviceAliases = [
                'PAT'  => ['pat', 'partial acceptance'],
                'SAT'  => ['sat', 'satisfactory acceptance'],
                'SCI'  => ['sci', 'standards compliance'],
                'TA'   => ['technical assessment'],
                'DLA'  => ['design life'],
                'MA'   => ['modernization'],
                'RA'   => ['replacement advisory'],
                'UAP'  => ['uap', 'user awareness'],
                'MOCK' => ['mock', 'drill'],
                'QRT'  => ['qrt'],
                'DG'   => ['dg', 'digital governance'],
                'IEGS' => ['iegs', 'integrated engineering']
            ];
            ?>

            <div class="psga-services psga-services-two-column">

                <?php
                /*
                 * Keep the approved-service list in a fixed 2-column layout.
                 * Six services are shown in the left column and six in the
                 * right column so Point 2 stays compact and does not run
                 * below the page footer.
                 */
                $serviceItems = array_keys($serviceList);
                $serviceColumns = array_chunk($serviceItems, 6);
                ?>

                <?php foreach ($serviceColumns as $serviceColumn): ?>
                    <div class="psga-service-column">

                        <?php foreach ($serviceColumn as $serviceCode): ?>

                            <?php
                            $service = $serviceList[$serviceCode];

                            $checked = in_array(
                                $serviceCode,
                                $approvedCodes,
                                true
                            );

                            if (!$checked && !empty($approvedNames)) {
                                foreach ($approvedNames as $savedName) {
                                    if (
                                        $savedName === strtolower($service) ||
                                        strpos(
                                            $savedName,
                                            strtolower($service)
                                        ) !== false
                                    ) {
                                        $checked = true;
                                        break;
                                    }
                                }
                            }

                            if (!$checked && $selected !== '') {
                                foreach (
                                    ($serviceAliases[$serviceCode] ?? [])
                                    as $alias
                                ) {
                                    if (
                                        strpos(
                                            $selected,
                                            strtolower($alias)
                                        ) !== false
                                    ) {
                                        $checked = true;
                                        break;
                                    }
                                }
                            }
                            ?>

                            <div class="psga-service-option">
                                <span class="psga-service-check">
                                    <?= $checked ? '☑' : '☐' ?>
                                </span>

                                <span>
                                    <?=h($service)?>
                                </span>
                            </div>

                        <?php endforeach; ?>

                    </div>
                <?php endforeach; ?>

            </div>

            <p>
                <strong>
                    Only the services selected above shall form part of this Agreement.
                </strong>
            </p>

            <div class="fso-style-footer">

                <div>
                    WEB: www.nleta.org.in |
                    EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                </div>

                <div>
                    Regd. Office. : A-32, Commercial Complex, Govindpuram,
                    Ghaziabad, Uttar Pradesh – 201013
                </div>

                <div>
                    Tel. : +91 99900 29597, +91 99900 58799, +91 99105 89059
                </div>

            </div>

            <div class="psga-page-number">Page 1 of 4</div>

        </div>

        <!-- ================= PAGE 2 OF 4 ================= -->
        <div class="psga-page-paper">

            <div class="psga-watermark">
                <img src="../assets/images/nleta-logo.jpeg" alt="">
            </div>

            <div class="psga-doc-header fso-style-header">

                <img
                    src="../assets/images/nleta-logo.jpeg"
                    alt="NLETA Logo"
                    class="psga-doc-logo fso-style-logo">

                <div class="fso-style-agency">

                    <div class="fso-style-agency-name">
                        National Lift Escalator Testing Agency
                    </div>

                    <div class="fso-style-tagline">
                        "Raising Standards, Developing Skills in Elevator Safety."
                    </div>

                    <div class="fso-style-approval">
                        A Lift &amp; Escalator Safety Enhancement Organization,
                        (Center Code – SIMTP/UP/77)
                    </div>

                    <div class="fso-style-subtitle">
                        Certified Third-Party Inspection • Testing • Technical Auditing •
                        Risk Analysis • Engineering Consultancy
                    </div>

                </div>

            </div>

            <h2>3. Stakeholder Responsibilities</h2>

            <table class="psga-table-doc">

                <thead>
                <tr>
                    <th>Stakeholder</th>
                    <th>Responsibility</th>
                </tr>
                </thead>

                <tbody>

                <tr>
                    <td>Client / Owner / RWA</td>
                    <td>Asset access, records, coordination, statutory follow-up</td>
                </tr>

                <tr>
                    <td>AMC / Service Provider</td>
                    <td>Maintenance, repair, manpower, PPE, tools and safe work procedures</td>
                </tr>

                <tr>
                    <td>AMC Technician</td>
                    <td>Safe work practices and authentic maintenance records</td>
                </tr>

                <tr>
                    <td>NLETA</td>
                    <td>Independent assessment, governance review and advisory support</td>
                </tr>

                </tbody>

            </table>

            <h2>4. Safety &amp; PPE</h2>

            <p>
                All technical, maintenance, inspection and repair work shall be carried out by the
                executing service provider using appropriate PPE, insulated tools and safe work
                procedures as required by applicable law and industry standards. Responsibility for
                provision, enforcement and supervision of PPE shall remain solely with the executing
                service provider.
            </p>

            <p>
                Safety is a continuous operational discipline and each stakeholder shall promote safe
                practices within its area of control.
            </p>

            <h2>4A. Site Access, Safety &amp; Inspection Environment</h2>

            <p>
                The Client shall provide a safe and obstruction-free inspection environment including
                proper access, machine room access, pit access, landing access, adequate lighting and
                electrical safety wherever required.
            </p>

            <p>
                The AMC/OEM shall provide its own qualified technician(s), special tools, tackles,
                access keys and technical assistance required for inspection.
            </p>

            <p>
                NLETA engineers shall use their own prescribed Personal Protective Equipment (PPE),
                while PPE for AMC/OEM personnel shall remain the responsibility of the respective
                executing service provider.
            </p>

            <p>
                Inspection shall not commence if the working environment is considered unsafe by the
                NLETA Engineer.
            </p>

            <h2>5. Maintenance Governance</h2>

            <p>
                The Client shall ensure that preventive maintenance is carried out by its appointed
                service provider as per the agreed maintenance schedule and applicable statutory
                requirements. Failure to perform scheduled maintenance shall remain the responsibility
                of the Client and the executing service provider.
            </p>

            <h2>6. Digital Governance</h2>

            <p>
                Where selected, NLETA may provide governance support through its Engineering Governance
                System (EGS) for record management, reminder tracking, maintenance tracking, callback
                tracking and governance visibility. Digital tools support governance processes and do not
                replace statutory responsibility, operational supervision or human vigilance.
            </p>

            <div class="fso-style-footer">

                <div>
                    WEB: www.nleta.org.in |
                    EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                </div>

                <div>
                    Regd. Office. : A-32, Commercial Complex, Govindpuram,
                    Ghaziabad, Uttar Pradesh – 201013
                </div>

                <div>
                    Tel. : +91 99900 29597, +91 99900 58799, +91 99105 89059
                </div>

            </div>

            <div class="psga-page-number">Page 2 of 4</div>

        </div>

        <!-- ================= PAGE 3 OF 4 ================= -->
        <div class="psga-page-paper">

            <div class="psga-watermark">
                <img src="../assets/images/nleta-logo.jpeg" alt="">
            </div>

            <div class="psga-doc-header fso-style-header">

                <img
                    src="../assets/images/nleta-logo.jpeg"
                    alt="NLETA Logo"
                    class="psga-doc-logo fso-style-logo">

                <div class="fso-style-agency">

                    <div class="fso-style-agency-name">
                        National Lift Escalator Testing Agency
                    </div>

                    <div class="fso-style-tagline">
                        "Raising Standards, Developing Skills in Elevator Safety."
                    </div>

                    <div class="fso-style-approval">
                        A Lift &amp; Escalator Safety Enhancement Organization,
                        (Center Code – SIMTP/UP/77)
                    </div>

                    <div class="fso-style-subtitle">
                        Certified Third-Party Inspection • Testing • Technical Auditing •
                        Risk Analysis • Engineering Consultancy
                    </div>

                </div>

            </div>

            <h2>7. Communication Governance</h2>

            <p>
                For structured technical and operational coordination, an official governance communication
                group / channel may be created. All routine technical communication shall preferably be
                recorded through the designated channel. Commercial, financial, contractual and
                payment-related communication shall be handled separately through authorized company
                communication channels.
            </p>

            <h2>7A. Independent Inspection Governance</h2>

            <p>
                All inspections shall preferably be conducted in the presence of the Client's nominated
                representative and the appointed AMC/OEM representative.
            </p>

            <p>
                Where any technical disagreement arises during inspection, the Client's nominated
                representative shall act as the common coordination authority for recording observations.
                Engineering decisions, findings, priorities and recommendations issued by NLETA shall
                remain independent, evidence-based and free from commercial influence.
            </p>

            <p>
                Any attempt by the Client, AMC, OEM or any stakeholder to influence, suppress or alter
                engineering observations shall constitute unacceptable conduct under this Agreement.
            </p>

            <p>
                All parties shall maintain professional behaviour, mutual respect and workplace dignity
                throughout the inspection process.
            </p>

            <h2>8. Compliance Reference</h2>

            <p>
                The parties acknowledge the applicability of relevant state regulations and engineering
                standards including UP Lift &amp; Escalator Act 2024, Haryana Lift &amp; Escalator Act 2008,
                Bombay Lifts Act 1939, Delhi Lifts Rules 1942, IS 14665, IS 17900, EN 81 and other
                applicable engineering standards wherever relevant.
            </p>

            <h2>9. Limitation of Liability</h2>

            <p>
                NLETA services are advisory and governance-support services only. NLETA does not undertake
                operation, maintenance, repair execution, material supply, manpower deployment or
                operational control of the asset. Reports, observations and recommendations are based on
                the condition of the asset at the time of assessment and on records made available by the
                Client. Final operational responsibility shall remain with the Owner, Occupier and the
                appointed service provider.
            </p>

            <h2>10. Insurance Advisory</h2>

            <p>
                The Client is advised to maintain valid insurance coverage for the concerned assets.
                NLETA shall not be responsible for arranging or maintaining insurance coverage unless
                specifically agreed in writing.
            </p>

            <div class="fso-style-footer">

                <div>
                    WEB: www.nleta.org.in |
                    EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                </div>

                <div>
                    Regd. Office. : A-32, Commercial Complex, Govindpuram,
                    Ghaziabad, Uttar Pradesh – 201013
                </div>

                <div>
                    Tel. : +91 99900 29597, +91 99900 58799, +91 99105 89059
                </div>

            </div>

            <div class="psga-page-number">Page 3 of 4</div>

        </div>

        <!-- ================= PAGE 4 OF 4 ================= -->
        <div class="psga-page-paper">

            <div class="psga-watermark">
                <img src="../assets/images/nleta-logo.jpeg" alt="">
            </div>

            <div class="psga-doc-header fso-style-header">

                <img
                    src="../assets/images/nleta-logo.jpeg"
                    alt="NLETA Logo"
                    class="psga-doc-logo fso-style-logo">

                <div class="fso-style-agency">

                    <div class="fso-style-agency-name">
                        National Lift Escalator Testing Agency
                    </div>

                    <div class="fso-style-tagline">
                        "Raising Standards, Developing Skills in Elevator Safety."
                    </div>

                    <div class="fso-style-approval">
                        A Lift &amp; Escalator Safety Enhancement Organization,
                        (Center Code – SIMTP/UP/77)
                    </div>

                    <div class="fso-style-subtitle">
                        Certified Third-Party Inspection • Testing • Technical Auditing •
                        Risk Analysis • Engineering Consultancy
                    </div>

                </div>

            </div>

            <h2>11. Term &amp; Termination</h2>

            <p>
                This Agreement shall remain valid for the selected service period unless terminated earlier
                by either party through 30 days written notice. Obligations accrued prior to termination
                shall survive termination.
            </p>

            <h2>12. Governing Law</h2>

            <p>
                This Agreement shall be governed by the laws of India. Any dispute shall first be resolved
                amicably. Failing such resolution, jurisdiction shall be Ghaziabad.
            </p>

            <h2>13. Entire Agreement</h2>

            <p>
                This Agreement, together with the applicable Annexure(s) attached for the selected
                service(s), constitutes the entire understanding between the parties regarding the
                approved services.
            </p>

            <h2>14. Site Logistics &amp; Extended Duty</h2>

            <p>
                Where inspection is conducted outside the normal duty schedule, or at the specific
                request of the Client including late evening, night shift or multi-day inspection, the
                Client shall arrange and bear the actual cost of lodging, boarding, food, local
                conveyance and other agreed site logistics for the assigned NLETA Engineer(s),
                wherever applicable.
            </p>

            <p>
                <strong>Signatures</strong>
                (Both parties signing this agreement with mutual understanding to proceed further)
            </p>

            <div class="psga-signatures">

                <div>

                    <strong>For Client / Owner / RWA:</strong>

                    <div class="line">
                        Name: <?=h($p['display_owner'] ?: '')?>
                    </div>

                    <div class="line">
                        Designation:
                    </div>

                    <div class="line">
                        Signature:
                    </div>

                    <div class="line">
                        Date:
                    </div>

                </div>

                <div>

                    <strong>For NLETA (Authorized Signatory):</strong>

                    <div class="line">
                        Name:
                    </div>

                    <div class="line">
                        Designation:
                    </div>

                    <div class="line">
                        Signature :
                    </div>

                    <div class="line">
                        Date :
                    </div>

                </div>

            </div>

            <p class="center" style="margin-top:28px">

                <strong>
                    “NLETA – Safety Through System”
                </strong>

                <br>

                <strong>
                    Independent • Neutral • Evidence-Based • Transparent • Professional
                </strong>

            </p>

            <div class="fso-style-footer">

                <div>
                    WEB: www.nleta.org.in |
                    EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                </div>

                <div>
                    Regd. Office. : A-32, Commercial Complex, Govindpuram,
                    Ghaziabad, Uttar Pradesh – 201013
                </div>

                <div>
                    Tel. : +91 99900 29597, +91 99900 58799, +91 99105 89059
                </div>

            </div>

            <div class="psga-page-number">Page 4 of 4</div>

        </div>

<?php if ($withContainer): ?>
    </div>
<?php endif; ?>
<?php
    }
}
?>
