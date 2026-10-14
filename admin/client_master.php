<?php

/* =========================================================
   NLETA — PHASE 3
   CLIENT MASTER
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
   Convert database UTC datetime to IST.

   Required display format:
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

function redirect_client_master($message = '', $type = 'success')
{
    $url = 'client_master.php';

    if ($message !== '') {
        $url .= '?msg=' . urlencode($message);
        $url .= '&type=' . urlencode($type);
    }

    header('Location: ' . $url);
    exit;
}

/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['csrf_token'])) {
    generateCSRFToken();
}

/* =========================================================
   MESSAGE
========================================================= */

$message = $_GET['msg'] ?? '';
$messageType = $_GET['type'] ?? 'success';

/* =========================================================
   FORM DEFAULTS
========================================================= */

$form = [
    'Client_ID'         => '',
    'Organization'      => '',
    'Authorized_Person' => '',
    'Mobile'            => '',
    'Email'             => '',
    'GST'               => '',
    'Billing_Address'   => '',
    'Status'            => 'Active'
];

/* =========================================================
   HANDLE POST
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !validateCSRFToken($_POST['csrf_token'])
    ) {

        redirect_client_master(
            'Invalid security token. Please try again.',
            'error'
        );
    }

    $action = $_POST['action'] ?? '';

    /* =====================================================
       ADD CLIENT
    ===================================================== */

    if ($action === 'add') {

        $organization = trim($_POST['Organization'] ?? '');
        $authorized   = trim($_POST['Authorized_Person'] ?? '');
        $mobile       = trim($_POST['Mobile'] ?? '');
        $email        = trim($_POST['Email'] ?? '');
        $gst          = trim($_POST['GST'] ?? '');
        $address      = trim($_POST['Billing_Address'] ?? '');

        if ($organization === '') {

            redirect_client_master(
                'Organization / Client Name is required.',
                'error'
            );
        }

        if (
            $email !== '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {

            redirect_client_master(
                'Please enter a valid email address.',
                'error'
            );
        }

        $stmt = $conn->prepare("
            INSERT INTO client_master
            (
                Organization,
                Authorized_Person,
                Mobile,
                Email,
                GST,
                Billing_Address,
                Status
            )
            VALUES (?, ?, ?, ?, ?, ?, 'Active')
        ");

        if (!$stmt) {

            redirect_client_master(
                'Database error while preparing client creation.',
                'error'
            );
        }

        $stmt->bind_param(
            'ssssss',
            $organization,
            $authorized,
            $mobile,
            $email,
            $gst,
            $address
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            redirect_client_master(
                'Unable to create client: ' . $error,
                'error'
            );
        }

        $newClientId = $stmt->insert_id;

        $stmt->close();

        redirect_client_master(
            'Client created successfully. Client ID: ' . $newClientId,
            'success'
        );
    }

    /* =====================================================
       UPDATE CLIENT
    ===================================================== */

    if ($action === 'update') {

        $clientId = (int)($_POST['Client_ID'] ?? 0);

        $organization = trim($_POST['Organization'] ?? '');
        $authorized   = trim($_POST['Authorized_Person'] ?? '');
        $mobile       = trim($_POST['Mobile'] ?? '');
        $email        = trim($_POST['Email'] ?? '');
        $gst          = trim($_POST['GST'] ?? '');
        $address      = trim($_POST['Billing_Address'] ?? '');
        $status       = $_POST['Status'] ?? 'Active';

        if ($clientId <= 0) {

            redirect_client_master(
                'Invalid Client ID.',
                'error'
            );
        }

        if ($organization === '') {

            redirect_client_master(
                'Organization / Client Name is required.',
                'error'
            );
        }

        if (!in_array($status, ['Active', 'Inactive'], true)) {
            $status = 'Active';
        }

        if (
            $email !== '' &&
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {

            redirect_client_master(
                'Please enter a valid email address.',
                'error'
            );
        }

        $stmt = $conn->prepare("
            UPDATE client_master
            SET
                Organization = ?,
                Authorized_Person = ?,
                Mobile = ?,
                Email = ?,
                GST = ?,
                Billing_Address = ?,
                Status = ?
            WHERE Client_ID = ?
        ");

        if (!$stmt) {

            redirect_client_master(
                'Database error while preparing client update.',
                'error'
            );
        }

        $stmt->bind_param(
            'sssssssi',
            $organization,
            $authorized,
            $mobile,
            $email,
            $gst,
            $address,
            $status,
            $clientId
        );

        if (!$stmt->execute()) {

            $error = $stmt->error;

            $stmt->close();

            redirect_client_master(
                'Unable to update client: ' . $error,
                'error'
            );
        }

        $stmt->close();

        redirect_client_master(
            'Client updated successfully.',
            'success'
        );
    }

    /* =====================================================
       TOGGLE CLIENT STATUS
    ===================================================== */

    if ($action === 'toggle_status') {

        $clientId = (int)($_POST['Client_ID'] ?? 0);

        if ($clientId <= 0) {

            redirect_client_master(
                'Invalid Client ID.',
                'error'
            );
        }

        $stmt = $conn->prepare("
            SELECT Status
            FROM client_master
            WHERE Client_ID = ?
            LIMIT 1
        ");

        if (!$stmt) {

            redirect_client_master(
                'Database error.',
                'error'
            );
        }

        $stmt->bind_param(
            'i',
            $clientId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $client = $result->fetch_assoc();

        $stmt->close();

        if (!$client) {

            redirect_client_master(
                'Client not found.',
                'error'
            );
        }

        $newStatus = (
            $client['Status'] === 'Active'
        )
            ? 'Inactive'
            : 'Active';

        $stmt = $conn->prepare("
            UPDATE client_master
            SET Status = ?
            WHERE Client_ID = ?
        ");

        if (!$stmt) {

            redirect_client_master(
                'Database error.',
                'error'
            );
        }

        $stmt->bind_param(
            'si',
            $newStatus,
            $clientId
        );

        if (!$stmt->execute()) {

            $stmt->close();

            redirect_client_master(
                'Unable to change client status.',
                'error'
            );
        }

        $stmt->close();

        redirect_client_master(
            'Client status changed to ' . $newStatus . '.',
            'success'
        );
    }
}

/* =========================================================
   EDIT CLIENT
========================================================= */

$editClientId = (int)($_GET['edit'] ?? 0);

if ($editClientId > 0) {

    $stmt = $conn->prepare("
        SELECT
            Client_ID,
            Organization,
            Authorized_Person,
            Mobile,
            Email,
            GST,
            Billing_Address,
            Status
        FROM client_master
        WHERE Client_ID = ?
        LIMIT 1
    ");

    if ($stmt) {

        $stmt->bind_param(
            'i',
            $editClientId
        );

        $stmt->execute();

        $result = $stmt->get_result();

        $editClient = $result->fetch_assoc();

        $stmt->close();

        if ($editClient) {
            $form = $editClient;
        }
    }
}

/* =========================================================
   SEARCH / FILTER
========================================================= */

$search = trim($_GET['search'] ?? '');

$statusFilter = $_GET['status'] ?? '';

/* =========================================================
   STATISTICS
========================================================= */

$totalClients = 0;
$activeClients = 0;
$inactiveClients = 0;
$totalLinkedAssets = 0;

/* Total */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM client_master
");

if ($result) {

    $row = $result->fetch_assoc();

    $totalClients = (int)$row['total'];

    $result->free();
}

/* Active */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM client_master
    WHERE Status = 'Active'
");

if ($result) {

    $row = $result->fetch_assoc();

    $activeClients = (int)$row['total'];

    $result->free();
}

/* Inactive */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM client_master
    WHERE Status = 'Inactive'
");

if ($result) {

    $row = $result->fetch_assoc();

    $inactiveClients = (int)$row['total'];

    $result->free();
}

/* Linked Assets */

$result = $conn->query("
    SELECT COUNT(*) AS total
    FROM asset_master
    WHERE Client_ID IS NOT NULL
");

if ($result) {

    $row = $result->fetch_assoc();

    $totalLinkedAssets = (int)$row['total'];

    $result->free();
}

/* =========================================================
   CLIENT LIST
========================================================= */

$clients = [];

$sql = "
    SELECT
        c.Client_ID,
        c.Organization,
        c.Authorized_Person,
        c.Mobile,
        c.Email,
        c.GST,
        c.Billing_Address,
        c.Status,
        c.Created_At,
        c.Updated_At,
        COUNT(a.Asset_ID) AS Total_Assets

    FROM client_master c

    LEFT JOIN asset_master a
        ON a.Client_ID = c.Client_ID
";

$where = [];

$params = [];

$types = '';

/* Search */

if ($search !== '') {

    $where[] = "
        (
            c.Organization LIKE ?
            OR c.Authorized_Person LIKE ?
            OR c.Email LIKE ?
            OR c.Mobile LIKE ?
            OR c.GST LIKE ?
            OR CAST(c.Client_ID AS CHAR) LIKE ?
        )
    ";

    $searchValue = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {

        $params[] = $searchValue;

        $types .= 's';
    }
}

/* Status */

if (
    in_array(
        $statusFilter,
        ['Active', 'Inactive'],
        true
    )
) {

    $where[] = "c.Status = ?";

    $params[] = $statusFilter;

    $types .= 's';
}

/* WHERE */

if (!empty($where)) {

    $sql .=
        ' WHERE ' .
        implode(' AND ', $where);
}

/* GROUP */

$sql .= "
    GROUP BY
        c.Client_ID,
        c.Organization,
        c.Authorized_Person,
        c.Mobile,
        c.Email,
        c.GST,
        c.Billing_Address,
        c.Status,
        c.Created_At,
        c.Updated_At

    ORDER BY
        c.Client_ID DESC
";

$stmt = $conn->prepare($sql);

if ($stmt) {

    if (!empty($params)) {

        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {

        $clients[] = $row;
    }

    $stmt->close();
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

.client-master-page {

    position: relative;

    width: calc(100% - 250px);

    margin-left: 250px;

    margin-top: 65px;

    padding: 30px 24px 40px;

    min-height: calc(100vh - 83px);

    background: #f8fafc;

    box-sizing: border-box;

    overflow-x: hidden;

    color: #111827;
}

.client-master-page *,
.client-master-page *::before,
.client-master-page *::after {
    box-sizing: border-box;
}

/* =========================================================
   HEADER
========================================================= */

.cm-header {

    width: 100%;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 20px;

    margin-bottom: 22px;
}

.cm-title {
    min-width: 0;
}

.cm-title h1 {

    display: block;

    margin: 0;

    padding: 0;

    color: #111827;

    font-size: 28px;

    line-height: 1.3;

    font-weight: 700;
}

.cm-title p {

    display: block;

    margin: 6px 0 0;

    padding: 0;

    color: #64748b;

    font-size: 14px;

    line-height: 1.5;
}

.cm-add-btn {

    flex: 0 0 auto;

    border: 0;

    border-radius: 8px;

    padding: 11px 18px;

    background: #111827;

    color: #ffffff;

    font-family: inherit;

    font-size: 14px;

    font-weight: 600;

    cursor: pointer;

    white-space: nowrap;
}

.cm-add-btn:hover {
    opacity: .9;
}

/* =========================================================
   ALERT
========================================================= */

.cm-alert {

    width: 100%;

    margin: 0 0 18px;

    padding: 12px 15px;

    border-radius: 8px;

    font-size: 14px;

    line-height: 1.5;
}

.cm-alert.success {

    background: #ecfdf5;

    border: 1px solid #a7f3d0;

    color: #065f46;
}

.cm-alert.error {

    background: #fef2f2;

    border: 1px solid #fecaca;

    color: #991b1b;
}

/* =========================================================
   STATISTICS
========================================================= */

.cm-stats {

    width: 100%;

    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 16px;

    margin-bottom: 20px;
}

.cm-stat {

    min-width: 0;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    padding: 18px;

    box-shadow:
        0 2px 8px rgba(0, 0, 0, .04);
}

.cm-stat-label {

    margin: 0 0 8px;

    color: #64748b;

    font-size: 13px;
}

.cm-stat-value {

    margin: 0;

    color: #111827;

    font-size: 27px;

    font-weight: 700;
}

/* =========================================================
   FORM
========================================================= */

.cm-form-card {

    width: 100%;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    padding: 22px;

    margin-bottom: 20px;

    box-shadow:
        0 2px 8px rgba(0, 0, 0, .03);
}

.cm-form-title {

    margin: 0 0 20px;

    color: #111827;

    font-size: 19px;

    font-weight: 700;
}

.cm-form-grid {

    width: 100%;

    display: grid;

    grid-template-columns:
        repeat(2, minmax(0, 1fr));

    gap: 17px 18px;
}

.cm-field {

    min-width: 0;

    display: flex;

    flex-direction: column;

    gap: 7px;
}

.cm-field.full {
    grid-column: 1 / -1;
}

.cm-field label {

    color: #1f2937;

    font-size: 13px;

    font-weight: 600;
}

.cm-field input,
.cm-field textarea,
.cm-field select {

    display: block;

    width: 100%;

    max-width: 100%;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 10px 11px;

    background: #ffffff;

    color: #111827;

    font-family: inherit;

    font-size: 14px;

    outline: none;
}

.cm-field input,
.cm-field select {
    height: 42px;
}

.cm-field textarea {

    min-height: 95px;

    resize: vertical;
}

.cm-field input:focus,
.cm-field textarea:focus,
.cm-field select:focus {

    border-color: #64748b;

    box-shadow:
        0 0 0 2px rgba(100, 116, 139, .10);
}

.cm-form-actions {

    display: flex;

    align-items: center;

    gap: 10px;

    margin-top: 20px;
}

.cm-save {

    border: 0;

    border-radius: 7px;

    padding: 10px 19px;

    background: #111827;

    color: #ffffff;

    font-size: 14px;

    font-weight: 600;

    cursor: pointer;
}

.cm-cancel {

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 9px 18px;

    background: #ffffff;

    color: #374151;

    font-size: 14px;

    text-decoration: none;
}

/* =========================================================
   FILTER
========================================================= */

.cm-filter-card {

    width: 100%;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    padding: 15px;

    margin-bottom: 16px;
}

.cm-filter-form {

    width: 100%;

    display: flex;

    align-items: center;

    gap: 10px;
}

.cm-filter-form input {

    flex: 1 1 auto;

    min-width: 0;

    width: 100%;

    height: 42px;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 10px 12px;

    font-family: inherit;

    font-size: 14px;

    outline: none;
}

.cm-filter-form select {

    flex: 0 0 150px;

    width: 150px;

    height: 42px;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 10px;

    background: #ffffff;

    font-family: inherit;

    font-size: 14px;
}

.cm-filter-form button {

    flex: 0 0 auto;

    height: 42px;

    border: 0;

    border-radius: 7px;

    padding: 10px 17px;

    background: #374151;

    color: #ffffff;

    font-size: 14px;

    cursor: pointer;
}

.cm-reset {

    flex: 0 0 auto;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    height: 42px;

    border: 1px solid #d1d5db;

    border-radius: 7px;

    padding: 9px 14px;

    background: #ffffff;

    color: #374151;

    font-size: 14px;

    text-decoration: none;
}

/* =========================================================
   TABLE
========================================================= */

.cm-table-card {

    width: 100%;

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 12px;

    overflow: hidden;
}

.cm-table-scroll {

    display: block;

    width: 100%;

    max-width: 100%;

    overflow-x: auto;

    overflow-y: hidden;

    -webkit-overflow-scrolling: touch;
}

.cm-table {

    width: 100%;

    min-width: 1200px;

    border-collapse: collapse;

    border-spacing: 0;
}

.cm-table th {

    padding: 12px;

    background: #f8fafc;

    border-bottom: 1px solid #e5e7eb;

    color: #374151;

    font-size: 12px;

    font-weight: 700;

    text-align: left;

    white-space: nowrap;
}

.cm-table td {

    padding: 12px;

    border-bottom: 1px solid #f0f0f0;

    color: #374151;

    font-size: 13px;

    vertical-align: middle;
}

.cm-table tbody tr:last-child td {
    border-bottom: 0;
}

/* =========================================================
   CLICKABLE CLIENT
========================================================= */

.cm-client-link {

    display: inline-block;

    color: #111827;

    font-weight: 700;

    text-decoration: none;

    transition: color .15s ease;
}

.cm-client-link:hover {

    color: #2563eb;

    text-decoration: underline;
}

.cm-client-id {

    margin-top: 3px;

    color: #64748b;

    font-size: 11px;
}

.cm-assets {

    color: #111827;

    font-weight: 700;
}

/* =========================================================
   STATUS
========================================================= */

.cm-status {

    display: inline-block;

    padding: 4px 9px;

    border-radius: 20px;

    font-size: 11px;

    font-weight: 700;

    white-space: nowrap;
}

.cm-status.active {

    background: #dcfce7;

    color: #166534;
}

.cm-status.inactive {

    background: #fee2e2;

    color: #991b1b;
}

/* =========================================================
   ACTIONS
========================================================= */

.cm-actions {

    display: flex;

    align-items: center;

    gap: 6px;

    flex-wrap: wrap;
}

.cm-action {

    display: inline-block;

    border: 1px solid #d1d5db;

    border-radius: 6px;

    padding: 6px 9px;

    background: #ffffff;

    color: #374151;

    font-family: inherit;

    font-size: 11px;

    text-decoration: none;

    cursor: pointer;

    white-space: nowrap;
}

.cm-action:hover {
    background: #f9fafb;
}

.cm-empty {

    padding: 40px 20px !important;

    color: #64748b;

    text-align: center;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1200px) {

    .client-master-page {

        width: calc(100% - 250px);

        margin-left: 250px;

        padding:
            26px
            18px
            35px;
    }

    .cm-stats {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 900px) {

    .client-master-page {

        width: calc(100% - 250px);

        margin-left: 250px;

        padding:
            24px
            16px
            30px;
    }

    .cm-title h1 {
        font-size: 24px;
    }

    .cm-filter-form {
        flex-wrap: wrap;
    }

    .cm-filter-form input {
        flex: 1 1 100%;
    }
}

@media (max-width: 768px) {

    .client-master-page {

        width: 100%;

        margin-left: 0;

        padding:
            22px
            15px
            30px;
    }

    .cm-header {

        align-items: flex-start;

        flex-direction: column;

        gap: 12px;
    }

    .cm-title h1 {
        font-size: 23px;
    }

    .cm-stats {

        grid-template-columns:
            repeat(2, minmax(0, 1fr));

        gap: 12px;
    }

    .cm-form-grid {
        grid-template-columns: 1fr;
    }

    .cm-field.full {
        grid-column: auto;
    }

    .cm-filter-form {

        display: grid;

        grid-template-columns:
            1fr 1fr;

        gap: 10px;
    }

    .cm-filter-form input {
        grid-column: 1 / -1;
    }

    .cm-filter-form select {
        width: 100%;
    }

    .cm-filter-form button,
    .cm-reset {
        width: 100%;
    }
}

@media (max-width: 576px) {

    .client-master-page {

        width: 100%;

        margin-left: 0;

        padding:
            18px
            12px
            25px;
    }

    .cm-title h1 {
        font-size: 21px;
    }

    .cm-title p {
        font-size: 12px;
    }

    .cm-add-btn {
        width: 100%;
    }

    .cm-stats {
        grid-template-columns: 1fr;
    }

    .cm-form-card {
        padding: 16px;
    }

    .cm-form-actions {

        flex-direction: column;

        align-items: stretch;
    }

    .cm-save,
    .cm-cancel {
        width: 100%;
    }

    .cm-filter-form {

        display: flex;

        flex-direction: column;

        align-items: stretch;
    }

    .cm-filter-form input,
    .cm-filter-form select,
    .cm-filter-form button,
    .cm-reset {

        width: 100%;

        flex: none;
    }

    .cm-table-scroll {

        width: 100%;

        overflow-x: auto;
    }

    .cm-table {
        min-width: 1200px;
    }
}

@media (max-width: 400px) {

    .client-master-page {

        padding-left: 10px;

        padding-right: 10px;
    }

    .cm-title h1 {
        font-size: 20px;
    }

    .cm-form-card {
        padding: 14px;
    }
}

</style>

<!-- =========================================================
     CLIENT MASTER PAGE
========================================================= -->

<div class="client-master-page">

    <!-- HEADER -->

    <div class="cm-header">

        <div class="cm-title">

            <h1>
                Client Master
            </h1>

            <p>
                Manage NLETA clients and their linked assets.
            </p>

        </div>

        <?php if ($editClientId <= 0): ?>

            <button
                type="button"
                class="cm-add-btn"
                onclick="document.getElementById('clientForm').scrollIntoView({behavior:'smooth'});"
            >
                + Add Client
            </button>

        <?php endif; ?>

    </div>

    <!-- MESSAGE -->

    <?php if ($message !== ''): ?>

        <div
            class="cm-alert <?= $messageType === 'error' ? 'error' : 'success' ?>"
        >
            <?= h($message) ?>
        </div>

    <?php endif; ?>

    <!-- STATISTICS -->

    <div class="cm-stats">

        <div class="cm-stat">

            <div class="cm-stat-label">
                Total Clients
            </div>

            <div class="cm-stat-value">
                <?= $totalClients ?>
            </div>

        </div>

        <div class="cm-stat">

            <div class="cm-stat-label">
                Active Clients
            </div>

            <div class="cm-stat-value">
                <?= $activeClients ?>
            </div>

        </div>

        <div class="cm-stat">

            <div class="cm-stat-label">
                Inactive Clients
            </div>

            <div class="cm-stat-value">
                <?= $inactiveClients ?>
            </div>

        </div>

        <div class="cm-stat">

            <div class="cm-stat-label">
                Linked Assets
            </div>

            <div class="cm-stat-value">
                <?= $totalLinkedAssets ?>
            </div>

        </div>

    </div>

    <!-- ADD / EDIT CLIENT -->

    <div
        class="cm-form-card"
        id="clientForm"
    >

        <h2 class="cm-form-title">

            <?= $editClientId > 0
                ? 'Edit Client'
                : 'Add New Client'
            ?>

        </h2>

        <form method="post">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= h($_SESSION['csrf_token']) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="<?= $editClientId > 0 ? 'update' : 'add' ?>"
            >

            <?php if ($editClientId > 0): ?>

                <input
                    type="hidden"
                    name="Client_ID"
                    value="<?= (int)$form['Client_ID'] ?>"
                >

            <?php endif; ?>

            <div class="cm-form-grid">

                <div class="cm-field">

                    <label for="Organization">
                        Organization / Client Name *
                    </label>

                    <input
                        id="Organization"
                        type="text"
                        name="Organization"
                        maxlength="255"
                        required
                        value="<?= h($form['Organization']) ?>"
                    >

                </div>

                <div class="cm-field">

                    <label for="Authorized_Person">
                        Authorized Person
                    </label>

                    <input
                        id="Authorized_Person"
                        type="text"
                        name="Authorized_Person"
                        maxlength="255"
                        value="<?= h($form['Authorized_Person']) ?>"
                    >

                </div>

                <div class="cm-field">

                    <label for="Mobile">
                        Mobile
                    </label>

                    <input
                        id="Mobile"
                        type="text"
                        name="Mobile"
                        maxlength="15"
                        inputmode="numeric"
                        value="<?= h($form['Mobile']) ?>"
                    >

                </div>

                <div class="cm-field">

                    <label for="Email">
                        Email
                    </label>

                    <input
                        id="Email"
                        type="email"
                        name="Email"
                        maxlength="255"
                        value="<?= h($form['Email']) ?>"
                    >

                </div>

                <div class="cm-field">

                    <label for="GST">
                        GST
                    </label>

                    <input
                        id="GST"
                        type="text"
                        name="GST"
                        maxlength="100"
                        value="<?= h($form['GST']) ?>"
                    >

                </div>

                <?php if ($editClientId > 0): ?>

                    <div class="cm-field">

                        <label for="Status">
                            Status
                        </label>

                        <select
                            id="Status"
                            name="Status"
                        >

                            <option
                                value="Active"
                                <?= $form['Status'] === 'Active'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Active
                            </option>

                            <option
                                value="Inactive"
                                <?= $form['Status'] === 'Inactive'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                Inactive
                            </option>

                        </select>

                    </div>

                <?php endif; ?>

                <div class="cm-field full">

                    <label for="Billing_Address">
                        Billing Address
                    </label>

                    <textarea
                        id="Billing_Address"
                        name="Billing_Address"
                        maxlength="500"
                    ><?= h($form['Billing_Address']) ?></textarea>

                </div>

            </div>

            <div class="cm-form-actions">

                <button
                    type="submit"
                    class="cm-save"
                >
                    <?= $editClientId > 0
                        ? 'Update Client'
                        : 'Save Client'
                    ?>
                </button>

                <?php if ($editClientId > 0): ?>

                    <a
                        href="client_master.php"
                        class="cm-cancel"
                    >
                        Cancel
                    </a>

                <?php endif; ?>

            </div>

        </form>

    </div>

    <!-- SEARCH / FILTER -->

    <div class="cm-filter-card">

        <form
            method="get"
            class="cm-filter-form"
        >

            <input
                type="text"
                name="search"
                placeholder="Search client, email, mobile, GST or Client ID..."
                value="<?= h($search) ?>"
            >

            <select name="status">

                <option value="">
                    All Status
                </option>

                <option
                    value="Active"
                    <?= $statusFilter === 'Active'
                        ? 'selected'
                        : ''
                    ?>
                >
                    Active
                </option>

                <option
                    value="Inactive"
                    <?= $statusFilter === 'Inactive'
                        ? 'selected'
                        : ''
                    ?>
                >
                    Inactive
                </option>

            </select>

            <button type="submit">
                Search
            </button>

            <a
                href="client_master.php"
                class="cm-reset"
            >
                Reset
            </a>

        </form>

    </div>

    <!-- CLIENT TABLE -->

    <div class="cm-table-card">

        <div class="cm-table-scroll">

            <table class="cm-table">

                <thead>

                    <tr>

                        <th>Client</th>

                        <th>Authorized Person</th>

                        <th>Mobile</th>

                        <th>Email</th>

                        <th>GST</th>

                        <th>Assets</th>

                        <th>Status</th>

                        <th>Created</th>

                        <th>Updated</th>

                        <th>Actions</th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($clients)): ?>

                    <tr>

                        <td
                            colspan="10"
                            class="cm-empty"
                        >
                            No clients found.
                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($clients as $client): ?>

                        <tr>

                            <!-- CLIENT -->

                            <td>

                                <a
                                    href="client_profile.php?client_id=<?= (int)$client['Client_ID'] ?>"
                                    class="cm-client-link"
                                    title="View complete client profile"
                                >
                                    <?= h($client['Organization']) ?>
                                </a>

                                <div class="cm-client-id">

                                    Client ID:
                                    <?= (int)$client['Client_ID'] ?>

                                </div>

                            </td>

                            <!-- AUTHORIZED PERSON -->

                            <td>
                                <?= h(
                                    $client['Authorized_Person']
                                    ?: '—'
                                ) ?>
                            </td>

                            <!-- MOBILE -->

                            <td>
                                <?= h(
                                    $client['Mobile']
                                    ?: '—'
                                ) ?>
                            </td>

                            <!-- EMAIL -->

                            <td>
                                <?= h(
                                    $client['Email']
                                    ?: '—'
                                ) ?>
                            </td>

                            <!-- GST -->

                            <td>
                                <?= h(
                                    $client['GST']
                                    ?: '—'
                                ) ?>
                            </td>

                            <!-- ASSETS -->

                            <td>

                                <span class="cm-assets">

                                    <?= (int)$client['Total_Assets'] ?>

                                </span>

                            </td>

                            <!-- STATUS -->

                            <td>

                                <span
                                    class="cm-status <?= $client['Status'] === 'Active'
                                        ? 'active'
                                        : 'inactive'
                                    ?>"
                                >

                                    <?= h($client['Status']) ?>

                                </span>

                            </td>

                            <!-- CREATED — UTC → IST -->

                            <td>

                                <?php if (!empty($client['Created_At'])): ?>

                                    <?= h(
                                        formatIST(
                                            $client['Created_At']
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>

                            <!-- UPDATED — UTC → IST -->

                            <td>

                                <?php if (!empty($client['Updated_At'])): ?>

                                    <?= h(
                                        formatIST(
                                            $client['Updated_At']
                                        )
                                    ) ?>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>

                            <!-- ACTIONS -->

                            <td>

                                <div class="cm-actions">

                                    <a
                                        href="client_profile.php?client_id=<?= (int)$client['Client_ID'] ?>"
                                        class="cm-action"
                                    >
                                        View
                                    </a>

                                    <a
                                        href="client_master.php?edit=<?= (int)$client['Client_ID'] ?>"
                                        class="cm-action"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="post"
                                        style="display:inline;"
                                        onsubmit="return confirm('Change this client status?');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($_SESSION['csrf_token']) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="toggle_status"
                                        >

                                        <input
                                            type="hidden"
                                            name="Client_ID"
                                            value="<?= (int)$client['Client_ID'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="cm-action"
                                        >

                                            <?= $client['Status'] === 'Active'
                                                ? 'Deactivate'
                                                : 'Activate'
                                            ?>

                                        </button>

                                    </form>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>