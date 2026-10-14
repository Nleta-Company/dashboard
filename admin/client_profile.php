<?php

/* =========================================================
   NLETA — PHASE 3
   CLIENT PROFILE
   ADMIN MODULE
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('admin');

/*
   Database timestamps are treated as UTC.
   Display timestamps are converted to IST.
*/
date_default_timezone_set('Asia/Kolkata');

/* =========================================================
   HELPERS
========================================================= */

function h($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/*
   Convert UTC database timestamp to IST.

   Output:
   21-08-2026 04:56 PM
*/
function formatIST($datetime)
{
    if (empty($datetime)) {
        return 'Not available';
    }

    try {

        $utc = new DateTime(
            $datetime,
            new DateTimeZone('UTC')
        );

        $utc->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return $utc->format('d-m-Y h:i A');

    } catch (Exception $e) {

        return 'Not available';
    }
}

function show_value($value, $default = 'Not provided')
{
    if (
        $value === null ||
        trim((string)$value) === ''
    ) {
        return $default;
    }

    return h($value);
}

/* =========================================================
   CLIENT ID
========================================================= */

$clientId = (int)($_GET['client_id'] ?? 0);

if ($clientId <= 0) {

    header('Location: client_master.php');

    exit;
}

/* =========================================================
   GET CLIENT
========================================================= */

$stmt = $conn->prepare("
    SELECT *
    FROM client_master
    WHERE Client_ID = ?
    LIMIT 1
");

if (!$stmt) {

    die(
        'Unable to load client information. Database error: '
        . h($conn->error)
    );
}

$stmt->bind_param(
    'i',
    $clientId
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    die(
        'Unable to load client information. Database error: '
        . h($error)
    );
}

$result = $stmt->get_result();

$client = $result->fetch_assoc();

$stmt->close();

/* =========================================================
   CLIENT NOT FOUND
========================================================= */

if (!$client) {

    header(
        'Location: client_master.php?msg=' .
        urlencode('Client not found.') .
        '&type=error'
    );

    exit;
}

/* =========================================================
   GET LINKED ASSETS
========================================================= */

$assets = [];

$stmt = $conn->prepare("
    SELECT *
    FROM asset_master
    WHERE Client_ID = ?
    ORDER BY Asset_ID ASC
");

if (!$stmt) {

    die(
        'Unable to load assigned assets. Database error: '
        . h($conn->error)
    );
}

$stmt->bind_param(
    'i',
    $clientId
);

if (!$stmt->execute()) {

    $error = $stmt->error;

    $stmt->close();

    die(
        'Unable to load assigned assets. Database error: '
        . h($error)
    );
}

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {

    $assets[] = $row;
}

$stmt->close();

/* =========================================================
   ASSET COUNTS
========================================================= */

$totalAssets = count($assets);

$activeAssets = 0;

$inactiveAssets = 0;

foreach ($assets as $asset) {

    $assetStatus = strtolower(
        trim(
            (string)(
                $asset['Status']
                ?? $asset['status']
                ?? ''
            )
        )
    );

    if ($assetStatus === 'active') {

        $activeAssets++;

    } else {

        $inactiveAssets++;
    }
}

/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<style>

/* =========================================================
   GLOBAL
========================================================= */

html,
body {

    max-width: 100%;

    overflow-x: hidden !important;
}

/* =========================================================
   MAIN PAGE
========================================================= */

.client-profile-page {

    position: relative;

    width: calc(100% - 250px);

    margin-left: 250px;

    margin-top: 65px;

    padding:
        30px
        24px
        40px;

    min-height:
        calc(100vh - 83px);

    background: #f8fafc;

    box-sizing: border-box;

    overflow-x: hidden;

    color: #111827;
}

.client-profile-page *,
.client-profile-page *::before,
.client-profile-page *::after {

    box-sizing: border-box;
}

/* =========================================================
   HEADER
========================================================= */

.cp-header {

    width: 100%;

    display: flex;

    align-items: flex-start;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 22px;
}

.cp-heading {
    min-width: 0;
}

.cp-heading h1 {

    margin: 0;

    color: #111827;

    font-size: 28px;

    line-height: 1.3;

    font-weight: 700;
}

.cp-heading p {

    margin: 6px 0 0;

    color: #64748b;

    font-size: 14px;

    line-height: 1.5;
}

.cp-header-actions {

    display: flex;

    align-items: center;

    gap: 8px;

    flex-wrap: wrap;
}

.cp-btn {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    min-height: 40px;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 9px 14px;

    background: #ffffff;

    color: #374151;

    font-family: inherit;

    font-size: 13px;

    font-weight: 600;

    text-decoration: none;

    white-space: nowrap;
}

.cp-btn:hover {
    background: #f9fafb;
}

.cp-btn.primary {

    border-color: #111827;

    background: #111827;

    color: #ffffff;
}

/* =========================================================
   SUMMARY
========================================================= */

.cp-summary {

    width: 100%;

    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 14px;

    margin-bottom: 20px;
}

.cp-summary-card {

    min-width: 0;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 11px;

    padding: 16px;

    box-shadow:
        0 2px 8px rgba(0, 0, 0, .03);
}

.cp-summary-label {

    margin-bottom: 7px;

    color: #64748b;

    font-size: 12px;

    line-height: 1.4;
}

.cp-summary-value {

    color: #111827;

    font-size: 22px;

    line-height: 1.2;

    font-weight: 700;
}

/* =========================================================
   CARD
========================================================= */

.cp-card {

    width: 100%;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    margin-bottom: 20px;

    overflow: hidden;

    box-shadow:
        0 2px 8px rgba(0, 0, 0, .03);
}

.cp-card-header {

    padding:
        17px
        20px;

    border-bottom:
        1px solid #e5e7eb;

    background: #ffffff;
}

.cp-card-header h2 {

    margin: 0;

    color: #111827;

    font-size: 18px;

    line-height: 1.3;

    font-weight: 700;
}

.cp-card-body {
    padding: 20px;
}

/* =========================================================
   INFORMATION
========================================================= */

.cp-info-grid {

    width: 100%;

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 18px 24px;
}

.cp-info-item {
    min-width: 0;
}

.cp-info-item.full {
    grid-column: 1 / -1;
}

.cp-info-label {

    margin-bottom: 5px;

    color: #64748b;

    font-size: 12px;

    font-weight: 600;

    line-height: 1.4;
}

.cp-info-value {

    color: #111827;

    font-size: 14px;

    line-height: 1.5;

    word-break: break-word;
}

.cp-info-value.empty {
    color: #94a3b8;
}

/* =========================================================
   STATUS
========================================================= */

.cp-status {

    display: inline-block;

    padding:
        5px
        10px;

    border-radius: 20px;

    font-size: 11px;

    line-height: 1.2;

    font-weight: 700;
}

.cp-status.active {

    background: #dcfce7;

    color: #166534;
}

.cp-status.inactive {

    background: #fee2e2;

    color: #991b1b;
}

/* =========================================================
   ASSET TABLE
========================================================= */

.cp-table-wrap {

    width: 100%;

    overflow-x: auto;

    overflow-y: hidden;

    -webkit-overflow-scrolling: touch;
}

.cp-table {

    width: 100%;

    min-width: 1000px;

    border-collapse: collapse;

    border-spacing: 0;
}

.cp-table th {

    padding:
        12px;

    background: #f8fafc;

    border-bottom:
        1px solid #e5e7eb;

    color: #374151;

    font-size: 12px;

    font-weight: 700;

    text-align: left;

    white-space: nowrap;
}

.cp-table td {

    padding:
        12px;

    border-bottom:
        1px solid #f0f0f0;

    color: #374151;

    font-size: 13px;

    vertical-align: middle;
}

.cp-table tbody tr:last-child td {
    border-bottom: 0;
}

.cp-asset-name {

    color: #111827;

    font-weight: 700;
}

.cp-asset-id {

    margin-top: 3px;

    color: #64748b;

    font-size: 11px;
}

.cp-asset-status {

    display: inline-block;

    padding:
        4px
        9px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;
}

.cp-asset-status.active {

    background: #dcfce7;

    color: #166534;
}

.cp-asset-status.maintenance {

    background: #fef3c7;

    color: #92400e;
}

.cp-asset-status.out {

    background: #fee2e2;

    color: #991b1b;
}

.cp-asset-status.other {

    background: #e5e7eb;

    color: #374151;
}

.cp-empty {

    padding:
        45px
        20px !important;

    color: #64748b;

    text-align: center;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .client-profile-page {

        width: calc(100% - 250px);

        margin-left: 250px;

        padding:
            26px
            18px
            35px;
    }

    .cp-summary {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {

    .client-profile-page {

        width: calc(100% - 250px);

        margin-left: 250px;

        padding:
            24px
            16px
            30px;
    }

    .cp-heading h1 {
        font-size: 24px;
    }
}

@media (max-width: 768px) {

    .client-profile-page {

        width: 100%;

        margin-left: 0;

        padding:
            22px
            15px
            30px;
    }

    .cp-header {

        flex-direction: column;

        align-items: flex-start;
    }

    .cp-header-actions {
        width: 100%;
    }

    .cp-btn {
        flex: 1 1 auto;
    }

    .cp-summary {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        gap: 12px;
    }

    .cp-info-grid {
        grid-template-columns: 1fr;
    }

    .cp-info-item.full {
        grid-column: auto;
    }
}

@media (max-width: 576px) {

    .client-profile-page {

        width: 100%;

        margin-left: 0;

        padding:
            18px
            12px
            25px;
    }

    .cp-heading h1 {
        font-size: 21px;
    }

    .cp-heading p {
        font-size: 12px;
    }

    .cp-header-actions {

        flex-direction: column;

        align-items: stretch;
    }

    .cp-btn {

        width: 100%;

        flex: none;
    }

    .cp-summary {
        grid-template-columns: 1fr;
    }

    .cp-card-header {

        padding:
            15px
            16px;
    }

    .cp-card-body {

        padding: 16px;
    }

    .cp-table {
        min-width: 1000px;
    }
}

</style>

<!-- =========================================================
     CLIENT PROFILE PAGE
========================================================= -->

<div class="client-profile-page">

    <!-- HEADER -->

    <div class="cp-header">

        <div class="cp-heading">

            <h1>
                <?= h(
                    $client['Organization']
                    ?? 'Client'
                ) ?>
            </h1>

            <p>

                Client Profile

                &nbsp;•&nbsp;

                Client ID:
                <?= (int)$client['Client_ID'] ?>

            </p>

        </div>

        <div class="cp-header-actions">

            <a
                href="client_master.php"
                class="cp-btn"
            >
                ← Back to Clients
            </a>

            <a
                href="client_master.php?edit=<?= (int)$client['Client_ID'] ?>"
                class="cp-btn primary"
            >
                Edit Client
            </a>

        </div>

    </div>

    <!-- SUMMARY -->

    <div class="cp-summary">

        <div class="cp-summary-card">

            <div class="cp-summary-label">
                Client Status
            </div>

            <div class="cp-summary-value">

                <span
                    class="cp-status <?= (($client['Status'] ?? '') === 'Active')
                        ? 'active'
                        : 'inactive'
                    ?>"
                >
                    <?= h(
                        $client['Status']
                        ?? 'Unknown'
                    ) ?>
                </span>

            </div>

        </div>

        <div class="cp-summary-card">

            <div class="cp-summary-label">
                Total Assets
            </div>

            <div class="cp-summary-value">
                <?= $totalAssets ?>
            </div>

        </div>

        <div class="cp-summary-card">

            <div class="cp-summary-label">
                Active Assets
            </div>

            <div class="cp-summary-value">
                <?= $activeAssets ?>
            </div>

        </div>

        <div class="cp-summary-card">

            <div class="cp-summary-label">
                Other / Inactive Assets
            </div>

            <div class="cp-summary-value">
                <?= $inactiveAssets ?>
            </div>

        </div>

    </div>

    <!-- CLIENT INFORMATION -->

    <div class="cp-card">

        <div class="cp-card-header">

            <h2>
                Client Information
            </h2>

        </div>

        <div class="cp-card-body">

            <div class="cp-info-grid">

                <!-- CLIENT ID -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Client ID
                    </div>

                    <div class="cp-info-value">
                        <?= (int)$client['Client_ID'] ?>
                    </div>

                </div>

                <!-- ORGANIZATION -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Organization / Client Name
                    </div>

                    <div class="cp-info-value">

                        <?= show_value(
                            $client['Organization']
                            ?? null
                        ) ?>

                    </div>

                </div>

                <!-- AUTHORIZED PERSON -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Authorized Person
                    </div>

                    <div class="cp-info-value">

                        <?= show_value(
                            $client['Authorized_Person']
                            ?? null
                        ) ?>

                    </div>

                </div>

                <!-- MOBILE -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Mobile
                    </div>

                    <div class="cp-info-value">

                        <?= show_value(
                            $client['Mobile']
                            ?? null
                        ) ?>

                    </div>

                </div>

                <!-- EMAIL -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Email
                    </div>

                    <div class="cp-info-value">

                        <?= show_value(
                            $client['Email']
                            ?? null
                        ) ?>

                    </div>

                </div>

                <!-- GST -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        GST
                    </div>

                    <div class="cp-info-value">

                        <?= show_value(
                            $client['GST']
                            ?? null
                        ) ?>

                    </div>

                </div>

                <!-- STATUS -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Status
                    </div>

                    <div class="cp-info-value">

                        <span
                            class="cp-status <?= (($client['Status'] ?? '') === 'Active')
                                ? 'active'
                                : 'inactive'
                            ?>"
                        >
                            <?= h(
                                $client['Status']
                                ?? 'Unknown'
                            ) ?>
                        </span>

                    </div>

                </div>

                <!-- CREATED AT — UTC → IST -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Created At
                    </div>

                    <div class="cp-info-value">

                        <?php if (!empty($client['Created_At'])): ?>

                            <?= h(
                                formatIST(
                                    $client['Created_At']
                                )
                            ) ?>

                        <?php else: ?>

                            Not available

                        <?php endif; ?>

                    </div>

                </div>

                <!-- UPDATED AT — UTC → IST -->

                <div class="cp-info-item">

                    <div class="cp-info-label">
                        Updated At
                    </div>

                    <div class="cp-info-value">

                        <?php if (!empty($client['Updated_At'])): ?>

                            <?= h(
                                formatIST(
                                    $client['Updated_At']
                                )
                            ) ?>

                        <?php else: ?>

                            Not available

                        <?php endif; ?>

                    </div>

                </div>

                <!-- BILLING ADDRESS -->

                <div class="cp-info-item full">

                    <div class="cp-info-label">
                        Billing Address
                    </div>

                    <div class="cp-info-value">

                        <?php if (
                            !empty(
                                $client['Billing_Address']
                            )
                        ): ?>

                            <?= nl2br(
                                h(
                                    $client['Billing_Address']
                                )
                            ) ?>

                        <?php else: ?>

                            <span class="cp-info-value empty">
                                Not provided
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ASSIGNED ASSETS -->

    <div class="cp-card">

        <div class="cp-card-header">

            <h2>
                Assigned Assets / Lifts
                (<?= $totalAssets ?>)
            </h2>

        </div>

        <div class="cp-table-wrap">

            <table class="cp-table">

                <thead>

                    <tr>

                        <th>
                            Asset
                        </th>

                        <th>
                            Type
                        </th>

                        <th>
                            Category
                        </th>

                        <th>
                            Location
                        </th>

                        <th>
                            Status
                        </th>

                        <th>
                            Maintenance Interval
                        </th>

                        <th>
                            Maintenance Start
                        </th>

                        <th>
                            Created
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($assets)): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="cp-empty"
                        >

                            No assets are currently linked
                            to this client.

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($assets as $asset): ?>

                        <?php

                        $assetName =
                            $asset['Asset_Name']
                            ?? $asset['asset_name']
                            ?? 'Unnamed Asset';

                        $displayAssetId =
                            $asset['Display_Asset_ID']
                            ?? $asset['display_asset_id']
                            ?? '';

                        $assetType =
                            $asset['Asset_Type']
                            ?? $asset['asset_type']
                            ?? $asset['Type']
                            ?? $asset['type']
                            ?? '—';

                        $category =
                            $asset['Category']
                            ?? $asset['category']
                            ?? '—';

                        $location =
                            $asset['Location']
                            ?? $asset['location']
                            ?? '—';

                        $status =
                            $asset['Status']
                            ?? $asset['status']
                            ?? 'Unknown';

                        $maintenanceInterval =
                            $asset['Maintenance_Interval']
                            ?? $asset['maintenance_interval']
                            ?? '';

                        $maintenanceStart =
                            $asset['Maintenance_Start_Date']
                            ?? $asset['maintenance_start_date']
                            ?? '';

                        $createdAt =
                            $asset['Created_At']
                            ?? $asset['created_at']
                            ?? '';

                        $statusLower = strtolower(
                            trim(
                                (string)$status
                            )
                        );

                        if (
                            $statusLower === 'active'
                        ) {

                            $statusClass = 'active';

                        } elseif (
                            $statusLower === 'under maintenance' ||
                            $statusLower === 'maintenance'
                        ) {

                            $statusClass = 'maintenance';

                        } elseif (
                            $statusLower === 'out of service' ||
                            $statusLower === 'inactive'
                        ) {

                            $statusClass = 'out';

                        } else {

                            $statusClass = 'other';
                        }

                        ?>

                        <tr>

                            <!-- ASSET -->

                            <td>

                                <div class="cp-asset-name">

                                    <?= h(
                                        $assetName
                                    ) ?>

                                </div>

                                <div class="cp-asset-id">

                                    Asset ID:
                                    <?= (int)(
                                        $asset['Asset_ID']
                                        ?? 0
                                    ) ?>

                                    <?php if (
                                        $displayAssetId !== ''
                                    ): ?>

                                        &nbsp;•&nbsp;

                                        Display ID:
                                        <?= h(
                                            $displayAssetId
                                        ) ?>

                                    <?php endif; ?>

                                </div>

                            </td>

                            <!-- TYPE -->

                            <td>
                                <?= h($assetType) ?>
                            </td>

                            <!-- CATEGORY -->

                            <td>
                                <?= h($category) ?>
                            </td>

                            <!-- LOCATION -->

                            <td>
                                <?= h($location) ?>
                            </td>

                            <!-- STATUS -->

                            <td>

                                <span
                                    class="cp-asset-status <?= $statusClass ?>"
                                >
                                    <?= h($status) ?>
                                </span>

                            </td>

                            <!-- MAINTENANCE INTERVAL -->

                            <td>

                                <?php if (
                                    $maintenanceInterval !== ''
                                ): ?>

                                    <?= (int)$maintenanceInterval ?>
                                    days

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>

                            <!-- MAINTENANCE START -->

                            <td>

                                <?php if (
                                    !empty($maintenanceStart)
                                ): ?>

                                    <?= date(
                                        'd-m-Y',
                                        strtotime(
                                            $maintenanceStart
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>

                            <!-- CREATED — UTC → IST -->

                            <td>

                                <?php if (
                                    !empty($createdAt)
                                ): ?>

                                    <?= h(
                                        formatIST(
                                            $createdAt
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

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