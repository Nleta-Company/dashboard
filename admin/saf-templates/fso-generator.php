<?php
/* =========================================================
   NLETA SHARED FSO RENDERER — FSO-01
   ---------------------------------------------------------
   Updated according to:
   (FSO) final Service Offer.docx

   IMPORTANT:
   - Presentation only.
   - No pricing generation.
   - No Offer status changes.
   - No Lift/asset workflow changes.
   - Existing OLD FSO Point 2 (Selected Services) and
     Point 3 (Commercial Summary) are retained inside the
     new FSO as Sections 3A and 3B.
========================================================= */

if (!function_exists('nleta_fso_h')) {
    function nleta_fso_h($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}


if (!function_exists('nleta_fso_money')) {
    function nleta_fso_money($value): string
    {
        return number_format((float)$value, 2, '.', ',');
    }
}

if (!function_exists('nleta_fso_date')) {
    function nleta_fso_date($value, string $format, ?string $fallback = null): string
    {
        $value = trim((string)$value);

        if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return $fallback ?? 'Not Available';
        }

        try {
            $dt = new DateTime($value, new DateTimeZone('Asia/Kolkata'));
            return $dt->format($format);
        } catch (Throwable $e) {
            return $fallback ?? 'Not Available';
        }
    }
}

if (!function_exists('nleta_fso_client_value')) {
    function nleta_fso_client_value(array $client, array $keys, string $fallback = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $client)) {
                $value = trim((string)$client[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return $fallback;
    }
}

if (!function_exists('nleta_fso_offer_value')) {
    function nleta_fso_offer_value(array $offer, array $keys, string $fallback = ''): string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $offer)) {
                $value = trim((string)$offer[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return $fallback;
    }
}

if (!function_exists('nleta_fso_service_code')) {
    /**
     * Resolve the service code used by the FSO renderer.
     *
     * The Offer Items table is allowed to contain different/legacy
     * service_code values. Therefore the renderer first uses a known
     * code and then falls back to service_name text. This is display-only
     * and never changes the saved offer/pricing data.
     */
    function nleta_fso_service_code(array $item): string
    {
        $rawCode = strtoupper(trim((string)($item['service_code'] ?? '')));
        $rawName = strtoupper(trim((string)($item['service_name'] ?? '')));

        $normalize = static function (string $value): string {
            return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
        };

        $code = $normalize($rawCode);
        $name = $normalize($rawName);

        $aliases = [
            'PAT' => ['PAT', 'PARTIALACCEPTANCETEST', 'PARTIALACCEPTANCE', 'PARTIALACCEPTANCETESTING'],
            'SAT' => ['SAT', 'SATISFACTORYACCEPTANCETEST', 'SATISFACTORYACCEPTANCE', 'SATISFACTORYACCEPTANCETESTING'],
            'SCI' => ['SCI', 'STANDARDSCOMPLIANCEINSPECTION', 'STANDARDSCOMPLIANCE', 'COMPLIANCEINSPECTION'],
            'TA'  => ['TA', 'TECHNICALASSESSMENT', 'TECHNICALASSESSMENTREPORT'],
            'DLA' => ['DLA', 'DESIGNLIFEASSESSMENT', 'DESIGNLIFE'],
            'MA'  => ['MA', 'MODERNIZATIONADVISORY', 'MODERNISATIONADVISORY', 'MODERNIZATION'],
            'RA'  => ['RA', 'REPLACEMENTADVISORY', 'REPLACEMENTADVISORYSERVICE', 'REPLACEMENT'],
            'UAP' => ['UAP', 'USERAWARENESSPROGRAM', 'USERAWARENESS', 'AWARENESSPROGRAM'],
            'MDR' => ['MDR', 'MOCKDRILL', 'MOCKDRILLQRT', 'MOCKDRILLQRTActivation', 'QRTActivation'],
            'DGS' => ['DGS', 'DIGITALGOVERNANCESUPPORT', 'DIGITALGOVERNANCE', 'DIGITALGOVERNANCESYSTEM'],
            'IEGS'=> ['IEGS', 'INTEGRATEDENGINEERINGGOVERNANCESUPPORT', 'INTEGRATEDENGINEERINGGOVERNANCE']
        ];

        foreach ($aliases as $canonical => $list) {
            foreach ($list as $alias) {
                $aliasNorm = $normalize(strtoupper($alias));
                if ($code !== '' && $code === $aliasNorm) {
                    return $canonical;
                }
            }
        }

        // Service-name fallback. Order matters for PAT/SAT/SCI.
        if ($name !== '') {
            if (str_contains($name, 'PARTIALACCEPTANCE')) return 'PAT';
            if (str_contains($name, 'SATISFACTORYACCEPTANCE')) return 'SAT';
            if (str_contains($name, 'STANDARDSCOMPLIANCE')) return 'SCI';
            if (str_contains($name, 'TECHNICALASSESSMENT')) return 'TA';
            if (str_contains($name, 'DESIGNLIFE')) return 'DLA';
            if (str_contains($name, 'MODERNIZATION') || str_contains($name, 'MODERNISATION')) return 'MA';
            if (str_contains($name, 'REPLACEMENTADVISORY')) return 'RA';
            if (str_contains($name, 'USERAWARENESS')) return 'UAP';
            if (str_contains($name, 'MOCKDRILL') || str_contains($name, 'QRT')) return 'MDR';
            if (str_contains($name, 'DIGITALGOVERNANCE')) return 'DGS';
            if (str_contains($name, 'INTEGRATEDENGINEERINGGOVERNANCE')) return 'IEGS';
        }

        return $rawCode;
    }
}

function renderNletaFso(
    array $existingOffer,
    array $client = [],
    array $existingItems = [],
    array $offerAssets = [],
    $conn = null
): void {
    if (!$existingOffer) {
        return;
    }

    /* ---------------------------------------------------------
       SAVED COMMERCIAL VALUES — DO NOT RECALCULATE HERE
    --------------------------------------------------------- */
    $displaySubtotal = (float)($existingOffer['subtotal'] ?? 0);
    $displayGST = (float)($existingOffer['gst_amount'] ?? 0);
    $displayGrandTotal = (float)($existingOffer['grand_total'] ?? 0);
    $displayAdvance = (float)($existingOffer['payment_advance'] ?? 0);
    $displayServiceActivation = (float)($existingOffer['payment_service_activation'] ?? 0);
    $displayFinal = (float)($existingOffer['payment_final'] ?? 0);

    $offerDate = nleta_fso_date(
        $existingOffer['offer_date'] ?? '',
        'd / m / Y',
        '____ / ____ / ______'
    );

    /* ---------------------------------------------------------
       VALID UNTIL
       Prefer saved validity_date. If not available, use
       Offer Date + 30 days only for display.
    --------------------------------------------------------- */
    $rawValidity = trim((string)($existingOffer['validity_date'] ?? ''));

    if (
        $rawValidity !== '' &&
        $rawValidity !== '0000-00-00' &&
        $rawValidity !== '0000-00-00 00:00:00'
    ) {
        $fsoValidUntil = nleta_fso_date(
            $rawValidity,
            'd-m-Y',
            'Not Available'
        );
    } else {
        $rawOfferDate = trim((string)($existingOffer['offer_date'] ?? ''));

        try {
            if (
                $rawOfferDate !== '' &&
                $rawOfferDate !== '0000-00-00' &&
                $rawOfferDate !== '0000-00-00 00:00:00'
            ) {
                $validityFallback = new DateTime(
                    $rawOfferDate,
                    new DateTimeZone('Asia/Kolkata')
                );
                $validityFallback->modify('+30 days');
                $fsoValidUntil = $validityFallback->format('d-m-Y');
            } else {
                $fsoValidUntil = 'Not Available';
            }
        } catch (Throwable $e) {
            $fsoValidUntil = 'Not Available';
        }
    }

    /* ---------------------------------------------------------
       CLIENT INFORMATION
       ---------------------------------------------------------
       Field aliases are intentionally supported so this renderer
       can work with the existing Client Master / Owner data.
    --------------------------------------------------------- */
    $organizationName = nleta_fso_client_value(
        $client,
        [
            'Organization',
            'organization_name',
            'Organization_Name',
            'organization',
            'society_name',
            'Society_Name'
        ]
    );

    $projectName = nleta_fso_client_value(
        $client,
        [
            'Project_Name',
            'project_name',
            'Project',
            'project'
        ]
    );

    $siteAddress = nleta_fso_client_value(
        $client,
        [
            'Site_Address',
            'site_address',
            'site',
            'Location',
            'location'
        ]
    );

    if ($siteAddress === '') {
        $siteAddress = nleta_fso_client_value(
            $client,
            [
                'Billing_Address',
                'organization_address',
                'Organization_Address'
            ]
        );
    }

    $authorizedRepresentative = nleta_fso_client_value(
        $client,
        [
            'Authorized_Person',
            'Authorized_Representative',
            'authorized_person',
            'authorized_representative',
            'Contact_Person'
        ]
    );

    $mobile = nleta_fso_client_value(
        $client,
        [
            'Mobile',
            'mobile',
            'Phone',
            'phone',
            'Mobile_No',
            'mobile_no'
        ]
    );

    $email = nleta_fso_client_value(
        $client,
        [
            'Email',
            'email'
        ]
    );

    $numberOfLifts = nleta_fso_client_value(
        $client,
        [
            'Number_of_Lifts',
            'number_of_lifts',
            'lift_count',
            'Lift_Count',
            'No_of_Lifts'
        ]
    );

    $numberOfEscalators = nleta_fso_client_value(
        $client,
        [
            'Number_of_Escalators',
            'number_of_escalators',
            'escalator_count',
            'Escalator_Count',
            'No_of_Escalators'
        ]
    );

    $proposalReference = nleta_fso_offer_value(
        $existingOffer,
        [
            'proposal_reference',
            'proposal_ref',
            'reference_no',
            'reference'
        ],
        'TCSP-01'
    );

    /* ---------------------------------------------------------
       SAVED OFFER ASSET LOCATIONS
       --------------------------------------------------------- */
    $fsoAssetLocations = [];

    if (!empty($offerAssets)) {
        foreach ($offerAssets as $oa) {
            $fsoAssetLocations[] = [
                'asset_id' => (int)($oa['asset_id'] ?? 0),
                'display_asset_id' => trim((string)($oa['display_asset_id'] ?? '')),
                'asset_name' => trim((string)($oa['asset_name'] ?? '')),
                'location' => trim((string)($oa['location'] ?? ''))
            ];
        }
    }

    if (
        empty($fsoAssetLocations) &&
        $conn instanceof mysqli &&
        !empty($existingOffer['id'])
    ) {
        $stmt = $conn->prepare("
            SELECT
                oa.asset_id,
                a.display_asset_id,
                a.asset_name,
                a.location
            FROM nleta_offer_assets oa
            INNER JOIN assets a
                ON a.id = oa.asset_id
            WHERE oa.offer_id = ?
            ORDER BY oa.id ASC
        ");

        if ($stmt) {
            $offerId = (int)$existingOffer['id'];
            $stmt->bind_param('i', $offerId);

            if ($stmt->execute()) {
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $fsoAssetLocations[] = [
                        'asset_id' => (int)($row['asset_id'] ?? 0),
                        'display_asset_id' => trim(
                            (string)($row['display_asset_id'] ?? '')
                        ),
                        'asset_name' => trim(
                            (string)($row['asset_name'] ?? '')
                        ),
                        'location' => trim(
                            (string)($row['location'] ?? '')
                        )
                    ];
                }
            }

            $stmt->close();
        }
    }

    /* ---------------------------------------------------------
       TRAVEL / LODGING / LOCAL CONVEYANCE APPLICABILITY
       ---------------------------------------------------------
       This is stored per FSO + Asset and is selected by Admin
       in the Offer Generator. The renderer only reads the saved
       value; it never calculates or changes pricing.
       --------------------------------------------------------- */
    $fsoTravelApplicability = [];

    if (
        $conn instanceof mysqli &&
        !empty($existingOffer['id'])
    ) {
        $travelStmt = $conn->prepare("
            SELECT
                oa.asset_id,
                oa.travel_lodging_applicable,
                a.display_asset_id,
                a.asset_name,
                a.location
            FROM nleta_offer_assets oa
            INNER JOIN assets a
                ON a.id = oa.asset_id
            WHERE oa.offer_id = ?
            ORDER BY oa.id ASC
        ");

        if ($travelStmt) {
            $travelOfferId = (int)$existingOffer['id'];
            $travelStmt->bind_param('i', $travelOfferId);

            if ($travelStmt->execute()) {
                $travelResult = $travelStmt->get_result();
                while ($travelRow = $travelResult->fetch_assoc()) {
                    $fsoTravelApplicability[(int)$travelRow['asset_id']] = [
                        'applicable' => (int)($travelRow['travel_lodging_applicable'] ?? 0) === 1,
                        'display_asset_id' => trim((string)($travelRow['display_asset_id'] ?? '')),
                        'asset_name' => trim((string)($travelRow['asset_name'] ?? '')),
                        'location' => trim((string)($travelRow['location'] ?? ''))
                    ];
                }
            }

            $travelStmt->close();
        }
    }

    $travelApplicableAssets = [];
    $travelNotApplicableCount = 0;

    foreach ($fsoTravelApplicability as $travelAssetId => $travelData) {
        if (!empty($travelData['applicable'])) {
            $label = trim((string)($travelData['display_asset_id'] ?? ''));

            if ($label === '') {
                $label = trim((string)($travelData['asset_name'] ?? ''));
            }

            if ($label !== '') {
                $travelApplicableAssets[] = $label;
            }
        } else {
            $travelNotApplicableCount++;
        }
    }

    $travelApplicableCount = count($travelApplicableAssets);

    /*
     * Agreed Travel / Lodging / Local Conveyance amount.
     * This is stored at FSO level and is displayed separately.
     * It is NOT part of service subtotal, GST or Grand Total.
     */
    $travelLodgingAmount = max(
        0.00,
        (float)($existingOffer['travel_lodging_amount'] ?? 0)
    );

    $travelLodgingManagedBy = (string)($existingOffer['travel_lodging_managed_by'] ?? 'owner');
    if (!in_array($travelLodgingManagedBy, ['owner', 'nleta'], true)) {
        $travelLodgingManagedBy = 'owner';
    }

    /* Backward compatibility for older offers that already stored an amount. */
    if ($travelLodgingManagedBy === 'owner' && $travelLodgingAmount > 0) {
        $travelLodgingManagedBy = 'nleta';
    }

    /* ---------------------------------------------------------
       FSO ASSET COUNTS + FSO NUMBER
       ---------------------------------------------------------
       Count ONLY the assets linked to THIS FSO.

       assets.asset_type is the source of truth:
       - Lift      -> Number of Lifts
       - Escalator -> Number of Escalators

       IMPORTANT:
       If this FSO contains only Lift assets, only the
       Number of Lifts row is displayed.
       If it contains only Escalator assets, only the
       Number of Escalators row is displayed.
       If it contains both, both rows are displayed.

       There is intentionally NO fallback to the owner's total
       asset count.
    --------------------------------------------------------- */
    $fsoLiftCount = 0;
    $fsoEscalatorCount = 0;

    if (
        $conn instanceof mysqli &&
        !empty($existingOffer['id'])
    ) {
        $fsoAssetCountStmt = $conn->prepare("
            SELECT
                a.asset_type
            FROM nleta_offer_assets oa
            INNER JOIN assets a
                ON a.id = oa.asset_id
            WHERE oa.offer_id = ?
            ORDER BY oa.id ASC
        ");

        if ($fsoAssetCountStmt) {
            $fsoCountOfferId = (int)$existingOffer['id'];
            $fsoAssetCountStmt->bind_param('i', $fsoCountOfferId);

            if ($fsoAssetCountStmt->execute()) {
                $fsoAssetCountResult = $fsoAssetCountStmt->get_result();

                while ($fsoAssetCountRow = $fsoAssetCountResult->fetch_assoc()) {
                    $assetType = strtolower(
                        trim(
                            (string)($fsoAssetCountRow['asset_type'] ?? '')
                        )
                    );

                    if ($assetType === 'lift') {
                        $fsoLiftCount++;
                    } elseif ($assetType === 'escalator') {
                        $fsoEscalatorCount++;
                    }
                }
            }

            $fsoAssetCountStmt->close();
        }
    }

    /* Generated FSO number shown in Client Information. */
    $fsoNumber = trim(
        (string)(
            $existingOffer['offer_no']
            ?? $existingOffer['fso_no']
            ?? $existingOffer['FSO_no']
            ?? ''
        )
    );

    /* ---------------------------------------------------------
       SERVICE CODES PRESENT IN THE SAVED OFFER
    --------------------------------------------------------- */
    $selectedCodes = [];

    foreach ($existingItems as $item) {
        $code = nleta_fso_service_code($item);

        if ($code !== '') {
            $selectedCodes[$code] = true;
        }
    }

    /* Fallback if the Offer Master stores service selection. */
    $masterServiceCode = strtoupper(
        trim(
            (string)(
                $existingOffer['service_code']
                ?? $existingOffer['selected_service']
                ?? ''
            )
        )
    );

    if ($masterServiceCode !== '') {
        foreach (preg_split('/[,|]+/', $masterServiceCode) as $code) {
            $code = strtoupper(trim($code));
            if ($code !== '') {
                $selectedCodes[$code] = true;
            }
        }
    }

    $services = [
        'PAT' => [
            'name' => 'PAT',
            'scope' => 'Independent Safety Inspection'
        ],
        'SAT' => [
            'name' => 'SAT',
            'scope' => 'Quality & Performance Inspection'
        ],
        'SCI' => [
            'name' => 'SCI',
            'scope' => 'Standards Compliance Inspection'
        ],
        'DLA' => [
            'name' => 'DLA',
            'scope' => 'Design Life Assessment'
        ],
        'DGS' => [
            'name' => 'DGS',
            'scope' => 'Digital Governance System'
        ]
    ];

    $deliverables = [
        'PAT' => 'Safety Report + Risk Score + Management Brief',
        'SAT' => 'Quality Analysis Report',
        'SCI' => 'Standards Compliance Report',
        'DLA' => 'Remaining Useful Life Assessment Report',
        'DGS' => 'QR Digital Dashboard + Asset History'
    ];

?>
    <div id="fsoPrintArea" class="fso-paper-wrap">

        <div class="fso-paper">

            <!-- =================================================
                 PAGE 1
            ================================================== -->
            <div class="fso-page">

                <div class="fso-header">

                    <div class="fso-header-top">

                        <img
                            src="../assets/images/nleta-logo.jpeg"
                            alt="NLETA Logo"
                            class="fso-logo">

                        <div class="fso-agency">

                            <div class="fso-agency-name">
                                National Lift Escalator Testing Agency
                            </div>

                            <div class="fso-tagline">
                                "Raising Standards, Developing Skills in Elevator Safety."
                            </div>

                            <div class="fso-approval">
                                A Lift &amp; Escalator Safety Enhancement Organization,
                                (Center Code – SIMTP/UP/77)
                            </div>

                            <div class="fso-subtitle">
                                Certified Third-Party Inspection • Testing • Technical Auditing •
                                Risk Analysis • Engineering Consultancy
                            </div>

                        </div>

                    </div>

                </div>

                <div class="fso-title">
                    FINAL SERVICE OFFER (FSO-01)
                </div>

                <div class="fso-heading">
                    1. CLIENT INFORMATION
                </div>

                <table class="fso-client-table">

                    <thead>
                        <tr>
                            <th>Particular</th>
                            <th>Details</th>
                        </tr>
                    </thead>

                    <tbody>

                        <tr>
                            <td>FSO No.</td>
                            <td><?= nleta_fso_h($fsoNumber) ?></td>
                        </tr>


                        <tr>
                            <td>Organization / Society</td>
                            <td><?= nleta_fso_h($organizationName) ?></td>
                        </tr>

                        <tr>
                            <td>Site Address</td>
                            <td><?= nleta_fso_h($siteAddress) ?></td>
                        </tr>

                        <tr>
                            <td>Authorized Representative</td>
                            <td><?= nleta_fso_h($authorizedRepresentative) ?></td>
                        </tr>

                        <tr>
                            <td>Mobile</td>
                            <td><?= nleta_fso_h($mobile) ?></td>
                        </tr>

                        <tr>
                            <td>Email</td>
                            <td><?= nleta_fso_h($email) ?></td>
                        </tr>

                        <?php if ($fsoLiftCount > 0): ?>

                            <tr>
                                <td>Number of Lifts</td>
                                <td><?= nleta_fso_h((string)$fsoLiftCount) ?></td>
                            </tr>

                        <?php endif; ?>

                        <?php if ($fsoEscalatorCount > 0): ?>

                            <tr>
                                <td>Number of Escalators</td>
                                <td><?= nleta_fso_h((string)$fsoEscalatorCount) ?></td>
                            </tr>

                        <?php endif; ?>

                        <tr>
                            <td>Proposal Reference</td>
                            <td><?= nleta_fso_h($proposalReference) ?></td>
                        </tr>

                        <tr>
                            <td>Date</td>
                            <td><?= nleta_fso_h($offerDate) ?></td>
                        </tr>

                    </tbody>

                </table>

                <div class="fso-heading">
                    2. PURPOSE OF FINAL SERVICE OFFER
                </div>

                <div class="fso-paragraph">
                    This Final Service Offer (FSO) confirms only the engineering services
                    selected by the Client after review of the applicable technical requirements
                    and service conditions. NLETA functions as an <strong>independent and unbiased engineering
                        governance organization</strong> and does not replace the AMC,
                    maintenance contractor, repair agency, sales organization or equipment
                    supplier. All engineering activities shall be executed in accordance
                    with the applicable decision matrices and the approved NLETA SOP
                    framework.
                </div>


                <!-- =================================================
                     OLD FSO POINT 2 RETAINED
                     New FSO Section 3A
                ================================================== -->
                <div class="fso-heading">
                    3A. SELECTED SERVICE DETAILS &amp; COMMERCIAL VALUE
                </div>

                <?php
                /*
                 * FSO SERVICE/ASSET DISPLAY MAP
                 * --------------------------------
                 * 1. Every service gets an independent asset sequence.
                 * 2. Asset ID + Asset Name + Site Address always come
                 *    from the SAME saved FSO asset record.
                 * 3. service_code is NOT trusted blindly; legacy/blank
                 *    codes are resolved from service_name as well.
                 * 4. Aggregated asset-based items (quantity > 1 and no
                 *    explicit asset_id) are expanded visually into one
                 *    row per asset. This does NOT change saved pricing.
                 * 5. Society/client-level fees stay without an asset.
                 */

                $fsoAssetById = [];
                foreach ($fsoAssetLocations as $assetIndex => $assetRow) {
                    $savedAssetId = (int)($assetRow['asset_id'] ?? 0);
                    if ($savedAssetId > 0) {
                        $fsoAssetById[$savedAssetId] = $assetIndex;
                    }
                }

                $assetBasedServiceCodes = [
                    'PAT' => true,
                    'SAT' => true,
                    'SCI' => true,
                    'TA'  => true,
                    'DLA' => true,
                    'MA'  => true,
                    'RA'  => true,
                    'UAP' => true,
                    'MDR' => true,
                    'DGS' => true,
                    'IEGS'=> true
                ];

                $fsoServiceAssetIndexes = [];
                $fsoDisplayRows = [];

                foreach ($existingItems as $item) {
                    $canonicalServiceCode = nleta_fso_service_code($item);
                    $serviceName = trim((string)($item['service_name'] ?? ''));
                    $itemQuantity = (float)($item['quantity'] ?? 0);
                    $itemRate = (float)($item['rate'] ?? 0);
                    $itemAmount = (float)($item['amount'] ?? 0);
                    $explicitAssetId = (int)($item['asset_id'] ?? 0);

                    /*
                     * Explicit asset_id always wins. If the Offer Item has
                     * an asset reference, we use that exact asset/address.
                     */
                    if ($explicitAssetId > 0 && isset($fsoAssetById[$explicitAssetId])) {
                        $assetIndex = $fsoAssetById[$explicitAssetId];
                        $assetRow = $fsoAssetLocations[$assetIndex];

                        $fsoDisplayRows[] = [
                            'asset_id' => trim((string)($assetRow['display_asset_id'] ?? '')),
                            'asset_name' => trim((string)($assetRow['asset_name'] ?? '')),
                            'site_address' => trim((string)($assetRow['location'] ?? '')),
                            'service_name' => $serviceName,
                            'qty' => $itemQuantity,
                            'rate' => $itemRate,
                            'amount' => $itemAmount
                        ];

                        continue;
                    }

                    /*
                     * Asset-based service without explicit asset_id.
                     *
                     * If quantity represents multiple assets, expand the
                     * single commercial item into one display row per saved
                     * FSO asset. Amount is divided only for DISPLAY so the
                     * displayed rows add back to the original item amount.
                     */
                    /* DGS has both society-level and asset-level fees.
                     * A platform/society fee must NOT be attached to an asset.
                     * Only the per-asset governance charge is expanded. */
                    $isSocietyLevelFee = (
                        $canonicalServiceCode === 'DGS' &&
                        preg_match('/\bPLATFORM\s+FEE\b|\bSOCIETY\s+PLATFORM\b|\bASSOCIATION\s+PLATFORM\b/i', $serviceName)
                    );

                    if (
                        isset($assetBasedServiceCodes[$canonicalServiceCode]) &&
                        !$isSocietyLevelFee &&
                        count($fsoAssetLocations) > 0
                    ) {
                        $serviceKey = $canonicalServiceCode !== ''
                            ? $canonicalServiceCode
                            : '__UNKNOWN__';

                        if (!isset($fsoServiceAssetIndexes[$serviceKey])) {
                            $fsoServiceAssetIndexes[$serviceKey] = 0;
                        }

                        $startIndex = (int)$fsoServiceAssetIndexes[$serviceKey];

                        $roundedQty = (int)round($itemQuantity);

                        /*
                         * A quantity of 2/3/... means this item is an
                         * aggregated per-asset charge. A quantity of 1 is
                         * one service row and consumes one asset.
                         */
                        $expandCount = max(1, $roundedQty);

                        /* Never create more visual asset rows than the
                         * saved FSO asset list contains. */
                        $available = count($fsoAssetLocations) - $startIndex;
                        if ($available <= 0) {
                            $available = count($fsoAssetLocations);
                            $startIndex = 0;
                            $fsoServiceAssetIndexes[$serviceKey] = 0;
                        }

                        $expandCount = min($expandCount, $available);

                        if ($expandCount === 1) {
                            $assetRow = $fsoAssetLocations[$startIndex];

                            $fsoDisplayRows[] = [
                                'asset_id' => trim((string)($assetRow['display_asset_id'] ?? '')),
                                'asset_name' => trim((string)($assetRow['asset_name'] ?? '')),
                                'site_address' => trim((string)($assetRow['location'] ?? '')),
                                'service_name' => $serviceName,
                                'qty' => $itemQuantity > 0 ? $itemQuantity : 1,
                                'rate' => $itemRate,
                                'amount' => $itemAmount
                            ];
                        } else {
                            $displayAmountPerAsset = $itemAmount / $expandCount;

                            for ($i = 0; $i < $expandCount; $i++) {
                                $assetPos = $startIndex + $i;
                                $assetRow = $fsoAssetLocations[$assetPos];

                                $fsoDisplayRows[] = [
                                    'asset_id' => trim((string)($assetRow['display_asset_id'] ?? '')),
                                    'asset_name' => trim((string)($assetRow['asset_name'] ?? '')),
                                    'site_address' => trim((string)($assetRow['location'] ?? '')),
                                    'service_name' => $serviceName,
                                    'qty' => 1,
                                    'rate' => $itemRate,
                                    'amount' => $displayAmountPerAsset
                                ];
                            }
                        }

                        $fsoServiceAssetIndexes[$serviceKey] = $startIndex + $expandCount;
                        continue;
                    }

                    /* Client/society-level or otherwise non-asset fee. */
                    $fsoDisplayRows[] = [
                        'asset_id' => '',
                        'asset_name' => '',
                        'site_address' => '',
                        'service_name' => $serviceName,
                        'qty' => $itemQuantity,
                        'rate' => $itemRate,
                        'amount' => $itemAmount
                    ];
                }
                ?>

                <table class="fso-service-table">

                    <thead>
                        <tr>
                            <th>Asset ID / Name</th>
                            <th>Service(s)</th>
                            <th>Site Address</th>
                            <th>Qty</th>
                            <th>Rate</th>
                            <th>Amount</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php if (!empty($fsoDisplayRows)): ?>

                            <?php foreach ($fsoDisplayRows as $displayRow): ?>

                                <?php
                                $displayQty = rtrim(
                                    rtrim(
                                        number_format(
                                            (float)($displayRow['qty'] ?? 0),
                                            2,
                                            '.',
                                            ''
                                        ),
                                        '0'
                                    ),
                                    '.'
                                );

                                if ($displayQty === '') {
                                    $displayQty = '0';
                                }
                                ?>

                                <tr>

                                    <td class="fso-asset-cell">
                                        <?php if (($displayRow['asset_id'] ?? '') !== '' || ($displayRow['asset_name'] ?? '') !== ''): ?>
                                            <?php if (($displayRow['asset_id'] ?? '') !== ''): ?>
                                                <strong><?= nleta_fso_h($displayRow['asset_id']) ?></strong>
                                            <?php endif; ?>
                                            <?php if (($displayRow['asset_name'] ?? '') !== ''): ?>
                                                <span><?= nleta_fso_h($displayRow['asset_name']) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?= nleta_fso_h($displayRow['service_name'] ?? '') ?>
                                    </td>

                                    <td>
                                        <?= nleta_fso_h(
                                            ($displayRow['site_address'] ?? '') !== ''
                                                ? $displayRow['site_address']
                                                : '—'
                                        ) ?>
                                    </td>

                                    <td class="center">
                                        <?= nleta_fso_h($displayQty) ?>
                                    </td>

                                    <td class="right">
                                        ₹<?= nleta_fso_money($displayRow['rate'] ?? 0) ?>
                                    </td>

                                    <td class="right">
                                        ₹<?= nleta_fso_money($displayRow['amount'] ?? 0) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6" class="center">
                                    No saved service item available.
                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

                <div class="fso-total">

                    <div>
                        <strong>Subtotal:</strong>
                        ₹<?= nleta_fso_money($displaySubtotal) ?>
                    </div>

                    <div>
                        <strong>GST @ 18%:</strong>
                        ₹<?= nleta_fso_money($displayGST) ?>
                    </div>

                    <div class="grand-total">
                        <strong>Grand Total:</strong>
                        ₹<?= nleta_fso_money($displayGrandTotal) ?>
                    </div>

                </div>

                <div class="fso-travel-note">
                    <strong>Travel, Lodging &amp; Local Conveyance:</strong>
                    Travel, lodging and local conveyance expenses, wherever applicable,
                    shall be payable separately for
                    <?php if ($travelApplicableCount > 0): ?>
                        <strong>Asset ID(s): <?= nleta_fso_h(implode(', ', $travelApplicableAssets)) ?></strong>.
                    <?php elseif (!empty($fsoTravelApplicability) && $travelNotApplicableCount === count($fsoTravelApplicability)): ?>
                        <strong>the selected service location(s)</strong> and shall be treated as Not Applicable.
                    <?php else: ?>
                        <strong>the selected service location(s)</strong>.
                    <?php endif; ?>

                    <?php if ($travelApplicableCount > 0): ?>
                        The mutually agreed amount, as discussed over phone, shall be
                        <?php if ($travelLodgingManagedBy === 'owner'): ?>
                            <strong>Managed by the Owner</strong>.
                        <?php elseif ($travelLodgingAmount > 0): ?>
                            <strong>₹<?= nleta_fso_money($travelLodgingAmount) ?></strong>.
                        <?php else: ?>
                            <strong>as mutually agreed</strong>.
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <!-- =================================================
                     OLD FSO POINT 3 RETAINED
                     New FSO Section 3B
                ================================================== -->
                <div class="fso-heading">
                    3B. COMMERCIAL SUMMARY
                </div>

                <div class="fso-commercial">

                    <strong>Payment Terms</strong>

                    <ul>
                        <li>
                            Advance: 50% with Agreement (PSGA) activation,
                            i.e. ₹<?= nleta_fso_money($displayAdvance) ?>
                        </li>

                        <li>
                            Before Final Checklist: 25% on Final Checklist Completion,
                            i.e. ₹<?= nleta_fso_money($displayServiceActivation) ?>
                        </li>

                        <li>
                            Balance Before Report Issue: 25% Final Payment,
                            i.e. ₹<?= nleta_fso_money($displayFinal) ?>
                        </li>
                    </ul>

                    <div>
                        <strong>PSGA No.:</strong>
                        <?= nleta_fso_h($existingOffer['psga_no'] ?? '') ?>
                    </div>

                    <div>
                        <strong>Offer Status:</strong>
                        <?= nleta_fso_h($existingOffer['status'] ?? '') ?>
                    </div>

                    <div>
                        <strong>Offer Validity:</strong>
                        30 Days
                    </div>

                    <div>
                        <strong>Valid Until:</strong>
                        <?= nleta_fso_h($fsoValidUntil) ?>
                    </div>

                    <div>
                        <strong>Service Schedule:</strong>
                        As mutually agreed after PSGA execution.
                    </div>

                </div>

                <div class="fso-heading">
                    4. ENGINEERING ACTIVATION CLAUSE
                </div>

                <div class="fso-clauses">

                    <div><strong>4.1</strong> PAT may be directly selected by the Client.</div>

                    <div>
                        <strong>4.2</strong> SAT shall be activated only when selected by
                        the Client or recommended based on the applicable
                        technical requirements and service conditions.
                    </div>

                    <div>
                        <strong>4.3</strong> SCI shall be activated only in accordance
                        with the applicable technical requirements and service conditions.
                    </div>

                    <div>
                        <strong>4.4</strong> DLA shall be activated only in accordance
                        with the applicable technical requirements and service conditions.
                    </div>

                    <div>
                        <strong>4.5</strong> Commercial selection alone shall
                        <strong>not</strong> constitute technical approval.
                    </div>

                </div>

                <div class="fso-footer">

                    <div>
                        WEB: www.nleta.org.in |
                        EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                    </div>

                    <div>
                        Regd. Office. : A-32, Commercial Complex, Govindpuram,
                        Ghaziabad, Uttar Pradesh – 201013
                    </div>

                    <div>
                        Tel. : +91 99900 58799, +91 92116 93664, +91 99900 29597
                    </div>

                    <div class="fso-page-number">
                        Page 1 of 2
                    </div>

                </div>

            </div>


            <!-- =================================================
                 PAGE 2
            ================================================== -->
            <div class="fso-page page-break">

                <div class="fso-header">

                    <div class="fso-header-top">

                        <img
                            src="../assets/images/nleta-logo.jpeg"
                            alt="NLETA Logo"
                            class="fso-logo">

                        <div class="fso-agency">

                            <div class="fso-agency-name">
                                National Lift Escalator Testing Agency
                            </div>

                            <div class="fso-tagline">
                                "Raising Standards, Developing Skills in Elevator Safety."
                            </div>

                            <div class="fso-approval">
                                A Lift &amp; Escalator Safety Enhancement Organization,
                                (Center Code – SIMTP/UP/77)
                            </div>

                            <div class="fso-subtitle">
                                Certified Third-Party Inspection • Testing • Technical Auditing •
                                Risk Analysis • Engineering Consultancy
                            </div>

                        </div>

                    </div>

                </div>



                <div class="fso-heading">
                    5. DELIVERABLES
                </div>

                <table class="fso-deliverables-table">

                    <thead>
                        <tr>
                            <th>Selected Service</th>
                            <th>Deliverable</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($deliverables as $code => $deliverable): ?>

                            <tr>

                                <td class="center strong">
                                    <?= nleta_fso_h($code) ?>
                                </td>

                                <td>
                                    <?= nleta_fso_h($deliverable) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

                <div class="fso-heading">
                    6. COMMERCIAL CONDITIONS
                </div>

                <ul class="fso-condition-list">

                    <li>
                        Proposal validity: <strong>30 Days</strong> from the date of issue.
                    </li>

                    <li>
                        GST shall be charged extra as applicable.
                    </li>

                    <li>
                        Travel, lodging and local conveyance shall be payable wherever applicable.
                    </li>

                    <li>
                        Special testing equipment or additional site visits shall be quoted separately.
                    </li>

                    <li>
                        Service execution shall commence only after PSGA and SAF are completed.
                    </li>

                </ul>

                <div class="fso-heading">
                    7. SITE READINESS &amp; INSPECTION SUPPORT
                </div>

                <div class="fso-paragraph">
                    The Client shall ensure safe access to the lift/escalator,
                    adequate illumination, safe working area, uninterrupted power
                    availability (where required), and coordination with the appointed
                    AMC/OEM representative during inspection. Any travel, late working
                    hours, lodging, boarding or local conveyance required due to
                    Client-requested scheduling shall be borne by the Client,
                    wherever applicable.
                </div>

                <div class="fso-heading">
                    8. CLIENT ACCEPTANCE
                </div>

                <div class="fso-acceptance-checks">

                    <div>☐ We accept this Final Service Offer.</div>

                    <div>☐ We request a discussion before service activation.</div>

                </div>

                <div class="fso-acceptance-grid">

                    <div class="fso-acceptance-party">

                        <div class="fso-party-title">
                            For Client / Authorized Representative
                        </div>

                        <div>
                            <strong>Authorized Representative:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Designation:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Signature:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Date:</strong>
                            ____ / ____ / ______
                        </div>

                    </div>


                    <div class="fso-acceptance-party">

                        <div class="fso-party-title">
                            For NLETA
                        </div>

                        <div>
                            <strong>Authorized Signatory:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Designation:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Signature:</strong>
                            ______________________________
                        </div>

                        <div>
                            <strong>Date:</strong>
                            ____ / ____ / ______
                        </div>

                    </div>

                </div>

                <div class="fso-next-step">

                    <div class="fso-heading no-margin">
                        NEXT STEP AFTER ACCEPTANCE
                    </div>

                    <div class="fso-paragraph">
                        Upon approval, NLETA shall issue:
                    </div>

                    <ol>

                        <li>
                            <strong>PSGA</strong> – Professional Service &amp;
                            Governance Agreement
                        </li>

                        <li>
                            <strong>Applicable Service Schedule</strong>
                        </li>

                        <li>
                            <strong>SAF</strong> – Service Activation Form
                        </li>

                        <li>
                            Inspection Schedule &amp; Engineer Assignment
                        </li>

                    </ol>

                </div>

                <div class="fso-closing">
                    <strong>NLETA — Safety Through System</strong>
                    <br>
                    <em>
                        Engineering Governance • Independent Technical Inspection •
                        Digital Asset Reliability
                    </em>
                </div>

                <div class="fso-footer">

                    <div>
                        WEB: www.nleta.org.in |
                        EMAIL: info@nleta.org.in, inspnleta@gmail.com, legalnleta@gmail.com
                    </div>

                    <div>
                        Regd. Office. : A-32, Commercial Complex, Govindpuram,
                        Ghaziabad, Uttar Pradesh – 201013
                    </div>

                    <div>
                        Tel. : +91 99900 58799, +91 92116 93664, +91 99900 29597
                    </div>

                    <div class="fso-page-number">
                        Page 2 of 2
                    </div>

                </div>

            </div>

        </div>

    </div>

    <style>
        /* =========================================================
       FSO-01 — SCREEN / PRINT STYLING
    ========================================================= */

        .fso-paper-wrap {
            width: 100%;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
        }

        .fso-paper {
            width: 100%;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        .fso-page {
            position: relative;
            width: 210mm;
            min-height: 297mm;
            height: auto;
            margin: 0 auto 20px;
            padding: 8mm 11mm 19mm;
            box-sizing: border-box;
            background: #fff;
            overflow: visible;
            break-after: auto;
            page-break-after: auto;
        }

        .fso-page:last-child {
            break-after: auto;
            page-break-after: auto;
        }

        .fso-page::after {
            content: "";
            position: absolute;
            left: 50%;
            top: 52%;
            width: 145mm;
            height: 145mm;
            background-image: url("../assets/images/nleta-logo.jpeg");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: .055;
            transform: translate(-50%, -50%);
            pointer-events: none;
            z-index: 0;
        }

        .fso-page>* {
            position: relative;
            z-index: 1;
        }

        .fso-header {
            border-bottom: 2px solid #111;
            padding-bottom: 4px;
            margin-bottom: 4px;
        }

        .fso-header-top {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .fso-logo {
            width: 70px;
            height: 70px;
            object-fit: contain;
            flex: 0 0 70px;
        }

        .fso-agency {
            flex: 1;
            text-align: center;
            line-height: 1.1;
        }

        .fso-agency-name {
            color: #e91e63;
            font-size: 24px;
            font-weight: 700;
            line-height: 1.05;
        }

        .fso-tagline {
            font-size: 12px;
            font-weight: 700;
            margin-top: 2px;
        }

        .fso-approval {
            font-size: 10px;
            font-weight: 700;
            margin-top: 2px;
        }

        .fso-subtitle {
            font-size: 9.5px;
            font-weight: 600;
            margin-top: 2px;
        }

        .fso-contact {
            font-size: 9px;
            line-height: 1.25;
            text-align: center;
            margin-top: 3px;
        }

        .fso-title {
            text-align: center;
            font-size: 17px;
            font-weight: 700;
            text-decoration: underline;
            margin: 5px 0;
        }

        .fso-linked-docs {
            text-align: center;
            font-size: 10.5px;
            margin-bottom: 7px;
        }

        .fso-heading {
            font-size: 12px;
            font-weight: 700;
            text-decoration: underline;
            margin: 8px 0 5px;
            line-height: 1.2;
        }

        .fso-heading.no-margin {
            margin-top: 0;
        }

        .normal-text {
            font-weight: 400;
            text-decoration: none;
        }

        .fso-paragraph {
            font-size: 11.5px;
            line-height: 1.45;
            text-align: justify;
            margin: 2px 0 4px;
        }

        .fso-paragraph.small-gap {
            margin-top: 2px;
        }

        .fso-client-table,
        .fso-engineering-table,
        .fso-service-table,
        .fso-deliverables-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .fso-client-table th,
        .fso-client-table td,
        .fso-engineering-table th,
        .fso-engineering-table td,
        .fso-service-table th,
        .fso-service-table td,
        .fso-deliverables-table th,
        .fso-deliverables-table td {
            border: 1px solid #222;
            vertical-align: middle;
        }

        .fso-client-table th,
        .fso-engineering-table th,
        .fso-service-table th,
        .fso-deliverables-table th {
            background: #f3f3f3;
            font-weight: 700;
            text-align: center;
        }

        .fso-client-table th,
        .fso-client-table td {
            padding: 3px 4px;
            font-size: 10.5px;
            line-height: 1.25;
        }

        .fso-client-table th:first-child {
            width: 31%;
        }

        .fso-client-table td:first-child {
            font-weight: 700;
        }

        .fso-client-table td:last-child {
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .fso-engineering-table th,
        .fso-engineering-table td {
            padding: 3px 4px;
            font-size: 10.5px;
            line-height: 1.25;
        }

        .fso-engineering-table .select-col {
            width: 9%;
        }

        .fso-engineering-table .service-col {
            width: 18%;
        }

        .fso-service-table th,
        .fso-service-table td {
            padding: 3px 3px;
            font-size: 10px;
            line-height: 1.25;
        }

        .fso-service-table th:nth-child(1) {
            width: 12%;
        }

        .fso-service-table th:nth-child(2) {
            width: 27%;
        }

        .fso-service-table th:nth-child(3) {
            width: 28%;
        }

        .fso-service-table th:nth-child(4) {
            width: 7%;
        }

        .fso-service-table th:nth-child(5) {
            width: 10%;
        }

        .fso-service-table th:nth-child(6) {
            width: 10%;
        }

        .fso-service-table td:nth-child(1) {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .fso-service-table td:nth-child(2) {
            text-align: left !important;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .fso-service-table td:nth-child(3) {
            text-align: left !important;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .fso-service-table td:nth-child(n+4) {
            text-align: center;
        }

        .fso-asset-cell strong,
        .fso-asset-cell span {
            display: block;
        }

        .fso-asset-cell span {
            margin-top: 1px;
            font-size: 9px;
            line-height: 1.15;
        }

        .fso-deliverables-table th,
        .fso-deliverables-table td {
            padding: 3px 5px;
            font-size: 10px;
            line-height: 1.2;
        }

        .fso-deliverables-table th:first-child {
            width: 28%;
        }

        .center {
            text-align: center !important;
        }

        .right {
            text-align: right !important;
        }

        .strong {
            font-weight: 700;
        }

        .fso-total {
            margin-top: 3px;
            text-align: right;
            font-size: 10px;
            line-height: 1.35;
        }

        .fso-total .grand-total {
            font-size: 10.5px;
        }

        .fso-travel-note {
            margin-top: 6px;
            padding: 7px 9px;
            border: 1px solid #222;
            background: #fafafa;
            font-size: 10px;
            line-height: 1.35;
        }

        .fso-travel-note span {
            display: inline;
        }

        .fso-commercial {
            font-size: 10.5px;
            line-height: 1.3;
        }

        .fso-commercial ul {
            margin: 2px 0 3px 16px;
            padding: 0;
        }

        .fso-commercial li {
            margin-bottom: 1px;
        }

        .fso-clauses {
            font-size: 10.5px;
            line-height: 1.3;
        }

        .fso-clauses>div {
            margin-bottom: 1px;
        }

        .fso-bullets {
            font-size: 10.5px;
            line-height: 1.3;
            margin: 2px 0 3px 17px;
            padding: 0;
        }

        .fso-condition-list {
            font-size: 10.5px;
            line-height: 1.4;
            margin: 3px 0 8px 18px;
            padding: 0;
        }

        .fso-condition-list li {
            margin-bottom: 2px;
        }

        .fso-acceptance-checks {
            font-size: 11.5px;
            line-height: 1.55;
            margin: 2px 0 9px;
        }

        .fso-acceptance-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 7px;
        }

        .fso-acceptance-party {
            border: 1px solid #222;
            padding: 8px 9px;
            font-size: 10.5px;
            line-height: 1.8;
            min-height: 70px;
            box-sizing: border-box;
        }

        .fso-party-title {
            text-align: center;
            font-weight: 700;
            text-decoration: underline;
            margin-bottom: 3px;
        }

        .fso-next-step {
            margin-top: 8px;
        }

        .fso-next-step ol {
            font-size: 10.5px;
            line-height: 1.5;
            margin: 2px 0 0 20px;
            padding: 0;
        }

        .fso-closing {
            position: static !important;
            left: auto;
            right: auto;
            bottom: auto;
            text-align: center;
            font-size: 10.5px;
            line-height: 1.55;
            margin: 10px 0 8px;
        }

        .fso-footer {
            position: static;
            left: auto;
            right: auto;
            bottom: auto;
            border-top: 2px solid #111;
            text-align: center;
            font-size: 9.5px;
            line-height: 1.3;
            padding-top: 2px;
            margin-top: 8px;
        }

        .fso-page-number {
            margin-top: 2px;
            font-size: 8.5px;
            font-weight: 700;
            text-align: right;
            padding-right: 2px;
        }

        


        @media print {
            @page {
                size: A4 portrait;
                margin: 0 !important;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                width: 210mm !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body * {
                visibility: hidden !important;
            }

            #fsoPrintArea,
            #fsoPrintArea * {
                visibility: visible !important;
            }

            /* Original logical pages are used only as the source for
               the print paginator. They must never print directly. */
            #fsoPrintArea .fso-paper > .fso-page {
                display: none !important;
            }

            #fsoPrintArea .fso-print-pages {
                display: block !important;
                width: 210mm !important;
                margin: 0 !important;
                padding: 0 !important;
            }

            /* One generated .fso-print-page = exactly one physical A4 sheet. */
            #fsoPrintArea .fso-print-page {
                position: relative !important;
                display: block !important;
                width: 210mm !important;
                height: 297mm !important;
                min-height: 297mm !important;
                max-height: 297mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
                background: #fff !important;
                box-shadow: none !important;
                break-after: page !important;
                page-break-after: always !important;
            }

            #fsoPrintArea .fso-print-page:last-child {
                break-after: auto !important;
                page-break-after: auto !important;
            }

            /* Fixed physical header. */
            #fsoPrintArea .fso-print-page .fso-print-header {
                position: absolute !important;
                top: 8mm !important;
                left: 11mm !important;
                right: 11mm !important;
                width: auto !important;
                margin: 0 !important;
                padding: 0 0 4px 0 !important;
                box-sizing: border-box !important;
                background: #fff !important;
                z-index: 20 !important;
            }

            /* Fixed physical footer. */
            #fsoPrintArea .fso-print-page .fso-print-footer {
                position: absolute !important;
                left: 11mm !important;
                right: 11mm !important;
                bottom: 7mm !important;
                width: auto !important;
                margin: 0 !important;
                padding: 2px 0 0 0 !important;
                box-sizing: border-box !important;
                border-top: 2px solid #111 !important;
                background: #fff !important;
                z-index: 20 !important;
                text-align: center !important;
                font-size: 9.5px !important;
                line-height: 1.25 !important;
            }

            /* THIS IS THE IMPORTANT PART:
               content has a hard physical top and bottom boundary.
               The footer band is completely outside this box, so no
               text/table can ever print behind or above the footer. */
            #fsoPrintArea .fso-print-content {
                position: absolute !important;
                top: 31mm !important;
                left: 11mm !important;
                right: 11mm !important;
                bottom: 27mm !important;
                width: auto !important;
                height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box !important;
                overflow: hidden !important;
                z-index: 2 !important;
            }

            #fsoPrintArea .fso-print-content > * {
                position: relative !important;
                z-index: 3 !important;
            }

            /* Final NLETA closing slogan: fixed only on the last
               physical page, immediately above the fixed footer. */
            #fsoPrintArea .fso-print-page .fso-print-closing {
                position: absolute !important;
                left: 11mm !important;
                right: 11mm !important;
                margin: 0 !important;
                padding: 0 !important;
                box-sizing: border-box !important;
                text-align: center !important;
                background: #fff !important;
                z-index: 15 !important;
            }

            #fsoPrintArea .fso-print-content table {
                width: 100% !important;
                max-width: 100% !important;
                border-collapse: collapse !important;
                table-layout: fixed !important;
            }

            #fsoPrintArea .fso-print-content thead {
                display: table-header-group !important;
            }

            #fsoPrintArea .fso-print-content tr {
                break-inside: avoid !important;
                page-break-inside: avoid !important;
            }

            #fsoPrintArea .fso-print-page::after {
                content: "" !important;
                position: absolute !important;
                left: 50% !important;
                top: 52% !important;
                width: 145mm !important;
                height: 145mm !important;
                background-image: url("../assets/images/nleta-logo.jpeg") !important;
                background-repeat: no-repeat !important;
                background-position: center !important;
                background-size: contain !important;
                opacity: .055 !important;
                transform: translate(-50%, -50%) !important;
                pointer-events: none !important;
                z-index: 1 !important;
            }

            .screen-only {
                display: none !important;
            }
        }

    </style>

    <script>
    (function () {
        const printArea = document.getElementById('fsoPrintArea');
        if (!printArea) return;

        let originalHtml = null;
        let generated = false;

        function cloneClean(source, className) {
            const el = source.cloneNode(true);
            el.className = className;
            el.removeAttribute('id');
            return el;
        }

        function getSourceNodes() {
            const logicalPages = printArea.querySelectorAll('.fso-paper > .fso-page');
            const nodes = [];

            logicalPages.forEach(function (page) {
                page.childNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.classList.contains('fso-header')) return;
                    if (node.classList.contains('fso-footer')) return;
                    if (node.classList.contains('fso-closing')) return;
                    nodes.push(node);
                });
            });

            return nodes;
        }

        function addNormalBlock(pageContent, node) {
            const clone = node.cloneNode(true);
            pageContent.appendChild(clone);
            return clone;
        }

        function makeServiceTableFragments(table) {
            const rows = Array.from(table.querySelectorAll('tbody > tr'));
            const thead = table.querySelector('thead');
            return { rows: rows, thead: thead };
        }

        function createTableShell(table, thead) {
            const shell = table.cloneNode(false);
            shell.innerHTML = '';
            if (thead) shell.appendChild(thead.cloneNode(true));
            const tbody = document.createElement('tbody');
            shell.appendChild(tbody);
            return { shell: shell, tbody: tbody };
        }

        function fits(content) {
            /* Force layout before reading dimensions. */
            void content.offsetHeight;
            return content.scrollHeight <= content.clientHeight + 1;
        }

        function newPhysicalPage(headerSource, footerSource, pagesWrap) {
            const page = document.createElement('div');
            page.className = 'fso-print-page';

            /*
             * IMPORTANT: Do not depend on @media print CSS for pagination
             * measurements. The beforeprint event can run before the browser
             * applies print media styles, which makes clientHeight = 0 and
             * causes every row to be placed on the first page.
             *
             * Give the paginator its physical A4 dimensions inline so the
             * measurements are valid before window.print() starts.
             */
            page.style.cssText = [
                'position:relative',
                'display:block',
                'width:210mm',
                'height:297mm',
                'min-height:297mm',
                'max-height:297mm',
                'margin:0',
                'padding:0',
                'box-sizing:border-box',
                'overflow:hidden',
                'background:#fff',
                'break-after:page',
                'page-break-after:always'
            ].join(';');

            const header = cloneClean(headerSource, 'fso-print-header');
            const footer = cloneClean(footerSource, 'fso-print-footer');
            const content = document.createElement('div');
            content.className = 'fso-print-content';

            header.style.cssText += ';position:absolute;top:8mm;left:11mm;right:11mm;width:auto;margin:0;box-sizing:border-box;z-index:20;background:#fff;';
            footer.style.cssText += ';position:absolute;left:11mm;right:11mm;bottom:7mm;width:auto;margin:0;box-sizing:border-box;z-index:20;background:#fff;text-align:center;';

            /* 31mm top reserve + 27mm bottom reserve.
               Content height = 297 - 31 - 27 = 239mm. */
            content.style.cssText = [
                'position:absolute',
                'top:31mm',
                'left:11mm',
                'right:11mm',
                'bottom:27mm',
                'width:auto',
                'height:239mm',
                'margin:0',
                'padding:0',
                'box-sizing:border-box',
                'overflow:hidden',
                'z-index:2'
            ].join(';');

            page.appendChild(header);
            page.appendChild(content);
            page.appendChild(footer);
            pagesWrap.appendChild(page);

            return { page: page, content: content };
        }

        function paginateForPrint() {
            if (generated) return;
            generated = true;

            const logicalPages = printArea.querySelectorAll('.fso-paper > .fso-page');
            if (!logicalPages.length) return;

            const headerSource = logicalPages[0].querySelector('.fso-header');
            const footerSource = logicalPages[0].querySelector('.fso-footer');
            const closingSource = logicalPages[logicalPages.length - 1].querySelector('.fso-closing');
            if (!headerSource || !footerSource) return;

            const sourceNodes = getSourceNodes();

            const pagesWrap = document.createElement('div');
            pagesWrap.className = 'fso-print-pages';
            pagesWrap.style.cssText = 'display:block;width:210mm;margin:0;padding:0;box-sizing:border-box;';

            /* It must exist in the DOM with physical dimensions before we
               measure overflow. */
            printArea.appendChild(pagesWrap);

            let current = newPhysicalPage(headerSource, footerSource, pagesWrap);

            sourceNodes.forEach(function (node, nodeIndex) {
                /* Special handling for the 3A service table: split it row by row. */
                if (node.matches && node.matches('.fso-service-table')) {
                    const parts = makeServiceTableFragments(node);
                    let tablePart = null;

                    parts.rows.forEach(function (row) {
                        if (!tablePart) {
                            tablePart = createTableShell(node, parts.thead);
                            current.content.appendChild(tablePart.shell);

                            if (!fits(current.content) && current.content.children.length > 1) {
                                current.content.removeChild(tablePart.shell);
                                current = newPhysicalPage(headerSource, footerSource, pagesWrap);
                                tablePart = createTableShell(node, parts.thead);
                                current.content.appendChild(tablePart.shell);
                            }
                        }

                        const rowClone = row.cloneNode(true);
                        tablePart.tbody.appendChild(rowClone);

                        if (!fits(current.content)) {
                            tablePart.tbody.removeChild(rowClone);

                            /* If the row itself is taller than the available
                               area, allow it to stay on a page rather than
                               looping forever. */
                            if (tablePart.tbody.children.length === 0) {
                                current = newPhysicalPage(headerSource, footerSource, pagesWrap);
                                tablePart = createTableShell(node, parts.thead);
                                current.content.appendChild(tablePart.shell);
                                tablePart.tbody.appendChild(rowClone);
                            } else {
                                current = newPhysicalPage(headerSource, footerSource, pagesWrap);
                                tablePart = createTableShell(node, parts.thead);
                                current.content.appendChild(tablePart.shell);
                                tablePart.tbody.appendChild(rowClone);
                            }
                        }
                    });

                    return;
                }

                const clone = node.cloneNode(true);
                current.content.appendChild(clone);

                if (!fits(current.content)) {
                    current.content.removeChild(clone);
                    current = newPhysicalPage(headerSource, footerSource, pagesWrap);
                    current.content.appendChild(clone);

                    /* For an unusually large block, let it remain on its own
                       page. Normal FSO blocks are well below one page. */
                }
            });

            /* Remove any empty trailing page. */
            let allPages = Array.from(pagesWrap.querySelectorAll('.fso-print-page'));
            if (allPages.length > 1) {
                const last = allPages[allPages.length - 1];
                if (last.querySelector('.fso-print-content').children.length === 0) {
                    last.remove();
                }
            }

            /*
             * FINAL CLOSING BLOCK
             * -------------------------------------------------------
             * The NLETA safety slogan is NOT normal flowing content.
             * It belongs to the final physical page only and stays
             * immediately above the fixed footer. Therefore it is
             * removed from normal pagination and positioned in the
             * reserved bottom band of the LAST generated page.
             */
            allPages = Array.from(pagesWrap.querySelectorAll('.fso-print-page'));
            if (closingSource && allPages.length) {
                const lastPage = allPages[allPages.length - 1];
                const lastFooter = lastPage.querySelector('.fso-print-footer');
                const closing = cloneClean(closingSource, 'fso-print-closing');

                closing.style.cssText += [
                    ';position:absolute',
                    'left:11mm',
                    'right:11mm',
                    'bottom:27mm',
                    'margin:0',
                    'padding:0',
                    'box-sizing:border-box',
                    'text-align:center',
                    'font-size:10.5px',
                    'line-height:1.0',
                    'background:#fff',
                    'z-index:10'
                ].join(';');

                lastPage.appendChild(closing);

                /*
                 * Reserve the closing block's actual height so that the
                 * final normal content never enters the closing/footer
                 * band. The final page is measured after the clone is
                 * attached, then the content box is shortened accordingly.
                 */
                const footerHeightPx = lastFooter ? lastFooter.getBoundingClientRect().height : 0;
                const closingHeightPx = closing.getBoundingClientRect().height;
                const pageRect = lastPage.getBoundingClientRect();
                const pageHeightPx = pageRect.height || 1122.52;
                const pxPerMm = pageHeightPx / 297;

                /*
                 * FOOTER POSITION IS FIXED AT 7mm FROM THE BOTTOM.
                 * Closing text must sit EXACTLY 2px above the footer.
                 *
                 * Use pixels for this calculation instead of converting
                 * back and forth between mm/px. This avoids rounding issues
                 * in Chrome print preview.
                 */
                const footerBottomPx = 7 * pxPerMm;
                const closingGapPx = 2;
                const closingBottomPx =
                    footerBottomPx + footerHeightPx + closingGapPx;

                /*
                 * Reserve the complete closing + footer band on the LAST
                 * physical page. No normal content can enter this area.
                 */
                const contentBox = lastPage.querySelector('.fso-print-content');
                if (contentBox) {
                    const reservedBottomPx =
                        closingBottomPx + closingHeightPx + 1;

                    contentBox.style.bottom =
                        (reservedBottomPx / pxPerMm).toFixed(3) + 'mm';
                    contentBox.style.height = 'auto';
                }

                /*
                 * Exact final position:
                 * closing bottom = footer top + 2px.
                 * Footer itself remains fixed at 7mm from page bottom.
                 */
                closing.style.bottom = closingBottomPx.toFixed(2) + 'px';
                closing.style.margin = '0';
                closing.style.padding = '0';
                closing.style.lineHeight = '1.0';
            }
        }

        function restoreAfterPrint() {
            const generatedPages = printArea.querySelector('.fso-print-pages');
            if (generatedPages) generatedPages.remove();
            generated = false;
        }

        window.addEventListener('beforeprint', paginateForPrint);
        window.addEventListener('afterprint', restoreAfterPrint);

        /* Also support the existing Print / Save PDF button. */
        window.nletaPrintFso = function () {
            paginateForPrint();
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    window.print();
                });
            });
        };
    })();
    </script>
<?php
}
?>