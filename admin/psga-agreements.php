<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
/* =========================================================
   NLETA ADMIN — PSGA AGREEMENTS
   ---------------------------------------------------------
   PSGA is a separate agreement before SAF.

   FLOW:
   Advance Payment Verified
        ↓
   PSGA generated
        ↓
   Owner Confirmation
        ↓
   Admin Confirmation
        ↓
   SAF may be generated

   This page uses the existing `contracts` table as the
   permanent PSGA master because payments.PSGA_ID already
   points to contracts.PSGA_ID.

   PSGA document format follows the supplied 4-page PSGA:
   - Client / Owner / RWA / Organization
   - Ref. No.
   - Approved Services
   - Stakeholder Responsibilities
   - Safety & PPE
   - Maintenance Governance
   - Digital Governance
   - Communication Governance
   - Compliance Reference
   - Limitation of Liability
   - Insurance Advisory
   - Term & Termination
   - Governing Law
   - Entire Agreement / Annexures
   - Both-party signature blocks
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');
require_once('../config/timezone.php');

require_role('admin');

/*
 * NLETA TIMEZONE STANDARD
 * -----------------------
 * Application timezone: Asia/Kolkata (IST / UTC+05:30)
 *
 * PHP date/time operations use config/timezone.php.
 * MySQL session timezone is also explicitly set to IST so that
 * NOW(), CURRENT_TIMESTAMP and other MySQL-generated DATETIME
 * values used by this page are created in IST.
 *
 * IMPORTANT:
 * Existing plain MySQL DATETIME values have no timezone information.
 * They are therefore treated as NLETA application-time (IST) values
 * when displayed. We do NOT blindly interpret them as UTC and add
 * +05:30 again.
 */
try {
    if (isset($conn) && $conn instanceof mysqli) {
        /*
         * This affects NOW(), CURRENT_TIMESTAMP and this connection's
         * timezone handling only. It does not rewrite existing rows.
         */
        @$conn->query("SET time_zone = '+05:30'");
    }
} catch (Throwable $e) {
    /*
     * Do not break the PSGA page if the MySQL account does not have
     * permission to change the session timezone. PHP remains on IST.
     */
}

$error = '';
$success = '';

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/**
 * Format an NLETA application/database DATETIME as IST.
 *
 * Plain MySQL DATETIME values such as:
 *   2026-09-17 12:30:00
 * contain no timezone marker. NLETA stores application timestamps
 * in IST, so this value is interpreted as Asia/Kolkata and displayed
 * in IST without applying another +05:30 shift.
 *
 * If a value explicitly contains a timezone/UTC marker (Z or an
 * offset such as +00:00), it is converted to IST.
 */
function formatISTDateTime($value, string $format = 'd-m-Y h:i A'): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }

    $raw = trim((string)$value);

    try {
        $hasExplicitTimezone = (bool)preg_match(
            '/(?:Z|[+-]\\d{2}:?\\d{2})$/i',
            $raw
        );

        if ($hasExplicitTimezone) {
            $dt = new DateTime($raw);
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));
        } else {
            /*
             * Plain DB DATETIME = NLETA IST.
             * Do not treat it as UTC.
             */
            $dt = new DateTime(
                $raw,
                new DateTimeZone('Asia/Kolkata')
            );
        }

        return $dt->format($format) . ' IST';
    } catch (Throwable $e) {
        return $raw;
    }
}

/**
 * Format a DATE value in the NLETA application timezone.
 * DATE has no time component, so no timezone conversion is required.
 */
function formatISTDateOnly($value): string
{
    if ($value === null || trim((string)$value) === '') {
        return '—';
    }

    $raw = trim((string)$value);

    try {
        $dt = new DateTime(
            $raw,
            new DateTimeZone('Asia/Kolkata')
        );

        return $dt->format('d-m-Y');
    } catch (Throwable $e) {
        return $raw;
    }
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) c
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $ok = ((int)$stmt->get_result()->fetch_assoc()['c'] > 0);
    $stmt->close();
    return $ok;
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = ((int)$stmt->get_result()->fetch_assoc()['c'] > 0);
    $stmt->close();
    return $ok;
}

/* =========================================================
   MULTI-SERVICE HELPERS
========================================================= */
function psgaGetRequestServices(mysqli $conn, int $requestId, int $fallbackServiceId = 0): array
{
    $services = [];

    if (
        tableExists($conn, 'service_request_services') &&
        tableExists($conn, 'service_catalog')
    ) {
        $stmt = $conn->prepare("
            SELECT sc.id, sc.service_code, sc.service_name, sc.description
            FROM service_request_services srs
            INNER JOIN service_catalog sc ON sc.id = srs.service_id
            WHERE srs.request_id = ?
            ORDER BY srs.id ASC, sc.id ASC
        ");

        if ($stmt) {
            $stmt->bind_param('i', $requestId);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $services[] = $row;
                }
            }
            $stmt->close();
        }
    }

    if (
        !$services &&
        $fallbackServiceId > 0 &&
        tableExists($conn, 'service_catalog')
    ) {
        $stmt = $conn->prepare("
            SELECT id, service_code, service_name, description
            FROM service_catalog
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $fallbackServiceId);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc();
                if ($row) {
                    $services[] = $row;
                }
            }
            $stmt->close();
        }
    }

    return $services;
}

function psgaGetOfferServices(
    mysqli $conn,
    int $offerId,
    int $requestId = 0,
    int $fallbackServiceId = 0
): array {
    $services = [];

    if ($offerId > 0 && tableExists($conn, 'nleta_offer_items')) {
        $stmt = $conn->prepare("
            SELECT service_code, service_name
            FROM nleta_offer_items
            WHERE offer_id = ?
            ORDER BY id ASC
        ");

        if ($stmt) {
            $stmt->bind_param('i', $offerId);

            if ($stmt->execute()) {
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $code = trim((string)($row['service_code'] ?? ''));
                    $name = trim((string)($row['service_name'] ?? ''));

                    if ($code !== '' || $name !== '') {
                        $services[] = [
                            'id' => 0,
                            'service_code' => $code,
                            'service_name' => $name,
                            'description' => ''
                        ];
                    }
                }
            }

            $stmt->close();
        }
    }

    if (!$services && $requestId > 0) {
        $services = psgaGetRequestServices(
            $conn,
            $requestId,
            $fallbackServiceId
        );
    }

    if (!$services && $fallbackServiceId > 0) {
        $services = psgaGetRequestServices(
            $conn,
            0,
            $fallbackServiceId
        );
    }

    return $services;
}

function psgaServiceNames(array $services): string
{
    $names = [];

    foreach ($services as $service) {
        $code = trim((string)($service['service_code'] ?? ''));
        $name = trim((string)($service['service_name'] ?? ''));

        if ($code !== '' && $name !== '') {
            $names[] = $code . ' - ' . $name;
        } elseif ($name !== '') {
            $names[] = $name;
        } elseif ($code !== '') {
            $names[] = $code;
        }
    }

    return implode(', ', array_values(array_unique($names)));
}

function psgaServiceCodes(array $services): array
{
    $codes = [];

    foreach ($services as $service) {
        $code = strtoupper(trim((string)($service['service_code'] ?? '')));

        if ($code !== '') {
            $codes[] = $code;
        }
    }

    return array_values(array_unique($codes));
}

function psgaServicesHtml(array $services): string
{
    if (!$services) {
        return '—';
    }

    $html = '<div style="display:flex;flex-wrap:wrap;gap:6px;">';

    foreach ($services as $service) {
        $code = h($service['service_code'] ?? '');
        $name = h($service['service_name'] ?? '');

        $label = $name;

        if ($code !== '' && $name !== '') {
            $label = '<strong>' . $code . '</strong> — ' . $name;
        } elseif ($code !== '') {
            $label = '<strong>' . $code . '</strong>';
        }

        $html .= '<span style="display:inline-block;background:#eaf2ff;color:#0d6efd;border:1px solid #cfe0ff;border-radius:6px;padding:5px 8px;font-size:11px;">' . $label . '</span>';
    }

    $html .= '</div>';

    return $html;
}

/* =========================================================
   PSGA ↔ FSO MAPPING
   ---------------------------------------------------------
   One permanent PSGA may be used by multiple FSOs.
   Therefore FSO visibility must NOT depend only on Owner_ID
   or on contracts.Request_ID.

   This small mapping table records every FSO that has
   actually been processed through PSGA generation.
   It does NOT change Lift functionality.
========================================================= */
function ensurePsgaFsoMap(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS psga_fso_map (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            psga_id INT UNSIGNED NOT NULL,
            offer_id INT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_psga_fso_offer (offer_id),
            KEY idx_psga_id (psga_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    if (!$conn->query($sql)) {
        throw new Exception(
            'Unable to prepare PSGA-FSO mapping table: ' . $conn->error
        );
    }
}

function mapPsgaToFso(mysqli $conn, int $psgaId, int $offerId): void
{
    if ($psgaId <= 0 || $offerId <= 0) {
        throw new Exception('Invalid PSGA/FSO mapping.');
    }

    ensurePsgaFsoMap($conn);

    $stmt = $conn->prepare("
        INSERT INTO psga_fso_map (psga_id, offer_id, created_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            psga_id = VALUES(psga_id)
    ");

    if (!$stmt) {
        throw new Exception(
            'Unable to prepare PSGA-FSO mapping: ' . $conn->error
        );
    }

    $stmt->bind_param('ii', $psgaId, $offerId);

    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();

        throw new Exception(
            'Unable to save PSGA-FSO mapping: ' . $err
        );
    }

    $stmt->close();
}

/*
 * Backfill mappings for existing generated FSOs.
 * This is intentionally limited to offers whose PSGA_No
 * already exists in contracts. It does not create PSGAs.
 */
try {
    ensurePsgaFsoMap($conn);

    $backfill = $conn->query("
        INSERT IGNORE INTO psga_fso_map (psga_id, offer_id, created_at)
        SELECT
            c.PSGA_ID,
            o.id,
            NOW()
        FROM contracts c
        INNER JOIN nleta_offer_master o
            ON o.psga_no = c.PSGA_No
        WHERE c.PSGA_No IS NOT NULL
          AND TRIM(c.PSGA_No) <> ''
    ");

    if ($backfill === false) {
        $error = 'PSGA-FSO mapping preparation failed: ' . $conn->error;
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

/* =========================================================
   PSGA → ALL FSO ASSETS
   ---------------------------------------------------------
   A permanent PSGA can have multiple FSOs.
   The document must therefore show ALL assets belonging to
   ALL existing FSOs linked to this PSGA — not only the latest
   FSO.

   Sources:
   1. psga_fso_map (authoritative PSGA ↔ FSO mapping)
   2. nleta_offer_master.psga_no (legacy/fallback mapping)

   Only asset display IDs are shown in the PSGA document,
   matching the existing document format.
========================================================= */
function psgaGetAllFsoAssets(
    mysqli $conn,
    int $psgaId,
    string $psgaNo = ''
): array {
    $assets = [];

    if (
        $psgaId <= 0 ||
        !tableExists($conn, 'nleta_offer_assets') ||
        !tableExists($conn, 'assets') ||
        !tableExists($conn, 'nleta_offer_master')
    ) {
        return $assets;
    }

    /*
     * Use UNION so both mapped FSOs and legacy FSOs carrying
     * the same permanent PSGA number are included.
     *
     * DISTINCT is applied at PHP level as well, because the same
     * asset can legitimately occur in more than one FSO.
     */
    $sql = "
        SELECT
            a.id AS asset_id,
            a.display_asset_id,
            a.asset_name,
            a.location,
            o.id AS offer_id,
            o.offer_no
        FROM psga_fso_map pfm
        INNER JOIN nleta_offer_master o
            ON o.id = pfm.offer_id
        INNER JOIN nleta_offer_assets oa
            ON oa.offer_id = o.id
        INNER JOIN assets a
            ON a.id = oa.asset_id
        WHERE pfm.psga_id = ?

        UNION

        SELECT
            a.id AS asset_id,
            a.display_asset_id,
            a.asset_name,
            a.location,
            o.id AS offer_id,
            o.offer_no
        FROM nleta_offer_master o
        INNER JOIN nleta_offer_assets oa
            ON oa.offer_id = o.id
        INNER JOIN assets a
            ON a.id = oa.asset_id
        WHERE ?
              <> ''
          AND o.psga_no = ?

        ORDER BY offer_id ASC, asset_id ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return $assets;
    }

    $stmt->bind_param(
        'iss',
        $psgaId,
        $psgaNo,
        $psgaNo
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return $assets;
    }

    $result = $stmt->get_result();

    /*
     * Key by actual asset ID so an asset appearing in multiple
     * FSOs is displayed only once.
     */
    $seen = [];

    while ($row = $result->fetch_assoc()) {
        $assetId = (int)($row['asset_id'] ?? 0);

        $displayId = trim(
            (string)($row['display_asset_id'] ?? '')
        );

        $assetName = trim(
            (string)($row['asset_name'] ?? '')
        );

        $label = $displayId !== ''
            ? $displayId
            : $assetName;

        if ($label === '') {
            continue;
        }

        /*
         * Prefer the real asset ID for de-duplication.
         * If no ID is available, use the displayed label.
         */
        $uniqueKey = $assetId > 0
            ? 'id:' . $assetId
            : 'label:' . strtolower($label);

        if (isset($seen[$uniqueKey])) {
            continue;
        }

        $seen[$uniqueKey] = true;
        $assets[] = $label;
    }

    $stmt->close();

    return $assets;
}

/* =========================================================
   DELETE PSGA — ONLY WHEN NO FSO EXISTS
   ---------------------------------------------------------
   Permanent PSGA may be removed only when there is no FSO
   linked to it. If an FSO exists, only Delete FSO is allowed.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $psgaId = (int)($_POST['psga_id'] ?? 0);

        if ($psgaId <= 0) {
            $error = 'Invalid PSGA selected.';
        } else {
            try {
                ensurePsgaFsoMap($conn);

                $stmt = $conn->prepare("
                    SELECT PSGA_ID, PSGA_No, Status
                    FROM contracts
                    WHERE PSGA_ID = ?
                    LIMIT 1
                ");
                if (!$stmt) {
                    throw new Exception('Unable to load PSGA for deletion: ' . $conn->error);
                }

                $stmt->bind_param('i', $psgaId);
                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();
                    throw new Exception('Unable to load PSGA for deletion: ' . $err);
                }

                $psgaDeleteRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$psgaDeleteRow) {
                    throw new Exception('PSGA Agreement not found.');
                }

                $psgaNoToDelete = trim((string)($psgaDeleteRow['PSGA_No'] ?? ''));

                /* A mapped FSO means the permanent PSGA must be retained. */
                $fsoCheck = $conn->prepare("
                    SELECT pfm.offer_id, o.offer_no
                    FROM psga_fso_map pfm
                    INNER JOIN nleta_offer_master o
                        ON o.id = pfm.offer_id
                    WHERE pfm.psga_id = ?
                    LIMIT 1
                ");
                if (!$fsoCheck) {
                    throw new Exception('Unable to verify PSGA FSO link: ' . $conn->error);
                }
                $fsoCheck->bind_param('i', $psgaId);
                $fsoCheck->execute();
                $linkedFso = $fsoCheck->get_result()->fetch_assoc();
                $fsoCheck->close();

                /* Legacy / fallback FSO link check as an extra safety layer. */
                if (!$linkedFso && $psgaNoToDelete !== '') {
                    $legacyFsoCheck = $conn->prepare("
                        SELECT id, offer_no
                        FROM nleta_offer_master
                        WHERE psga_no = ?
                        LIMIT 1
                    ");
                    if (!$legacyFsoCheck) {
                        throw new Exception('Unable to verify legacy PSGA FSO link: ' . $conn->error);
                    }
                    $legacyFsoCheck->bind_param('s', $psgaNoToDelete);
                    $legacyFsoCheck->execute();
                    $linkedFso = $legacyFsoCheck->get_result()->fetch_assoc();
                    $legacyFsoCheck->close();
                }

                if ($linkedFso) {
                    throw new Exception(
                        'PSGA cannot be deleted because FSO ' .
                        trim((string)($linkedFso['offer_no'] ?? '')) .
                        ' is still linked to it. Delete the FSO first.'
                    );
                }

                /* Do not allow deletion if payment records still reference this PSGA. */
                if (
                    tableExists($conn, 'payments') &&
                    columnExists($conn, 'payments', 'PSGA_ID')
                ) {
                    $payCheck = $conn->prepare("SELECT COUNT(*) AS c FROM payments WHERE PSGA_ID = ?");
                    if ($payCheck) {
                        $payCheck->bind_param('i', $psgaId);
                        $payCheck->execute();
                        $paymentCount = (int)($payCheck->get_result()->fetch_assoc()['c'] ?? 0);
                        $payCheck->close();
                        if ($paymentCount > 0) {
                            throw new Exception(
                                'PSGA cannot be deleted because payment records are linked to it.'
                            );
                        }
                    }
                }

                $conn->begin_transaction();

                try {
                    if (tableExists($conn, 'psga_fso_map')) {
                        $mapDelete = $conn->prepare("DELETE FROM psga_fso_map WHERE psga_id = ?");
                        if ($mapDelete) {
                            $mapDelete->bind_param('i', $psgaId);
                            if (!$mapDelete->execute()) {
                                throw new Exception('Unable to remove PSGA-FSO mapping: ' . $mapDelete->error);
                            }
                            $mapDelete->close();
                        }
                    }

                    $delete = $conn->prepare("DELETE FROM contracts WHERE PSGA_ID = ? LIMIT 1");
                    if (!$delete) {
                        throw new Exception('Unable to prepare PSGA deletion: ' . $conn->error);
                    }
                    $delete->bind_param('i', $psgaId);
                    if (!$delete->execute()) {
                        $err = $delete->error;
                        $delete->close();
                        throw new Exception('Unable to delete PSGA: ' . $err);
                    }
                    if ($delete->affected_rows !== 1) {
                        $delete->close();
                        throw new Exception('PSGA was not deleted.');
                    }
                    $delete->close();

                    $conn->commit();

                    $success =
                        'PSGA ' . ($psgaNoToDelete ?: '#' . $psgaId) .
                        ' deleted successfully because no FSO was linked to it.';
                } catch (Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   DELETE FSO — NOT PSGA
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_fso'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $offerId = (int)($_POST['offer_id'] ?? 0);

        if ($offerId <= 0) {
            $error = 'Invalid FSO selected.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT
                        o.id AS offer_id,
                        o.offer_no,
                        o.psga_no,
                        o.request_id,
                        sr.owner_id
                    FROM nleta_offer_master o
                    LEFT JOIN service_requests sr
                        ON sr.id = o.request_id
                    WHERE o.id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to load FSO for deletion: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $offerId);

                if (!$stmt->execute()) {
                    $err = $stmt->error;
                    $stmt->close();

                    throw new Exception(
                        'Unable to load FSO for deletion: ' . $err
                    );
                }

                $fsoRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$fsoRow) {
                    throw new Exception('FSO / Offer not found.');
                }

                $offerNoToDelete = trim((string)$fsoRow['offer_no']);
                $psgaNoToKeep = trim((string)$fsoRow['psga_no']);

                if ($offerNoToDelete === '') {
                    throw new Exception(
                        'FSO number is missing. Deletion stopped.'
                    );
                }

                $conn->begin_transaction();

                try {
                    if (
                        tableExists($conn, 'payment_transactions') &&
                        columnExists($conn, 'payment_transactions', 'Offer_ID')
                    ) {
                        $paymentTxnDelete = $conn->prepare("
                            DELETE FROM payment_transactions
                            WHERE Offer_ID = ?
                        ");

                        if (!$paymentTxnDelete) {
                            throw new Exception(
                                'Unable to prepare payment transaction deletion: ' .
                                $conn->error
                            );
                        }

                        $paymentTxnDelete->bind_param('i', $offerId);

                        if (!$paymentTxnDelete->execute()) {
                            $paymentTxnError = $paymentTxnDelete->error;
                            $paymentTxnDelete->close();

                            throw new Exception(
                                'Unable to delete PSGA payment transactions: ' .
                                $paymentTxnError
                            );
                        }

                        $paymentTxnDelete->close();
                    }

                    if (
                        tableExists($conn, 'payment_schedule') &&
                        columnExists($conn, 'payment_schedule', 'Offer_ID')
                    ) {
                        $scheduleDelete = $conn->prepare("
                            DELETE FROM payment_schedule
                            WHERE Offer_ID = ?
                        ");

                        if (!$scheduleDelete) {
                            throw new Exception(
                                'Unable to prepare payment schedule deletion: ' .
                                $conn->error
                            );
                        }

                        $scheduleDelete->bind_param('i', $offerId);

                        if (!$scheduleDelete->execute()) {
                            $scheduleError = $scheduleDelete->error;
                            $scheduleDelete->close();

                            throw new Exception(
                                'Unable to delete PSGA payment schedule: ' .
                                $scheduleError
                            );
                        }

                        $scheduleDelete->close();
                    }

                    if (
                        tableExists($conn, 'payments') &&
                        columnExists($conn, 'payments', 'Offer_ID')
                    ) {
                        $paymentsDelete = $conn->prepare("
                            DELETE FROM payments
                            WHERE Offer_ID = ?
                        ");

                        if (!$paymentsDelete) {
                            throw new Exception(
                                'Unable to prepare payment record deletion: ' .
                                $conn->error
                            );
                        }

                        $paymentsDelete->bind_param('i', $offerId);

                        if (!$paymentsDelete->execute()) {
                            $paymentsError = $paymentsDelete->error;
                            $paymentsDelete->close();

                            throw new Exception(
                                'Unable to delete PSGA payment records: ' .
                                $paymentsError
                            );
                        }

                        $paymentsDelete->close();
                    }

                    if (tableExists($conn, 'nleta_offer_assets')) {
                        $assetDelete = $conn->prepare("
                            DELETE FROM nleta_offer_assets
                            WHERE offer_id = ?
                        ");

                        if (!$assetDelete) {
                            throw new Exception(
                                'Unable to prepare PSGA asset-link deletion: ' .
                                $conn->error
                            );
                        }

                        $assetDelete->bind_param('i', $offerId);

                        if (!$assetDelete->execute()) {
                            $assetError = $assetDelete->error;
                            $assetDelete->close();

                            throw new Exception(
                                'Unable to delete PSGA asset links: ' .
                                $assetError
                            );
                        }

                        $assetDelete->close();
                    }

                    if (tableExists($conn, 'psga_fso_map')) {
                        $mapDelete = $conn->prepare("
                            DELETE FROM psga_fso_map
                            WHERE offer_id = ?
                        ");

                        if (!$mapDelete) {
                            throw new Exception(
                                'Unable to prepare PSGA-FSO mapping deletion: ' .
                                $conn->error
                            );
                        }

                        $mapDelete->bind_param('i', $offerId);

                        if (!$mapDelete->execute()) {
                            $mapError = $mapDelete->error;
                            $mapDelete->close();

                            throw new Exception(
                                'Unable to delete PSGA-FSO mapping: ' .
                                $mapError
                            );
                        }

                        $mapDelete->close();
                    }

                    $offerDelete = $conn->prepare("
                        DELETE FROM nleta_offer_master
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if (!$offerDelete) {
                        throw new Exception(
                            'Unable to prepare FSO deletion: ' . $conn->error
                        );
                    }

                    $offerDelete->bind_param('i', $offerId);

                    if (!$offerDelete->execute()) {
                        $offerError = $offerDelete->error;
                        $offerDelete->close();

                        throw new Exception(
                            'Unable to delete PSGA ' .
                            $offerNoToDelete . ': ' . $offerError
                        );
                    }

                    if ($offerDelete->affected_rows !== 1) {
                        $offerDelete->close();

                        throw new Exception('FSO was not deleted.');
                    }

                    $offerDelete->close();

                    $conn->commit();

                    $success =
                        'FSO ' . $offerNoToDelete .
                        ' deleted successfully. Its payment records were also deleted. ' .
                        'Permanent PSGA ' . ($psgaNoToKeep ?: '—') .
                        ' was NOT deleted. Owner, Service Request and Assets remain safe.';
                } catch (Throwable $inner) {
                    $conn->rollback();
                    throw $inner;
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   REGENERATE PSGA AGREEMENT
   ---------------------------------------------------------
   Regenerate / re-sync the permanent PSGA document using
   the latest FSO mapped to this PSGA.

   IMPORTANT:
   - Same permanent PSGA_No / PSGA_ID is retained.
   - No new PSGA record is created.
   - Existing PSGA status is preserved.
   - Latest FSO, selected services and linked asset IDs are
     re-synced so the agreement can be reviewed/printed again.
   - This does not modify Lift functionality.
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['regenerate_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $psgaId = (int)($_POST['psga_id'] ?? 0);

        if ($psgaId <= 0) {
            $error = 'Invalid PSGA selected.';
        } else {
            try {
                ensurePsgaFsoMap($conn);

                $psgaStmt = $conn->prepare("
                    SELECT
                        c.PSGA_ID,
                        c.PSGA_No,
                        c.Owner_ID,
                        c.Request_ID,
                        c.Asset_ID,
                        c.Status
                    FROM contracts c
                    WHERE c.PSGA_ID = ?
                    LIMIT 1
                ");

                if (!$psgaStmt) {
                    throw new Exception(
                        'Unable to load PSGA for regeneration: ' . $conn->error
                    );
                }

                $psgaStmt->bind_param('i', $psgaId);

                if (!$psgaStmt->execute()) {
                    $err = $psgaStmt->error;
                    $psgaStmt->close();
                    throw new Exception(
                        'Unable to load PSGA for regeneration: ' . $err
                    );
                }

                $psgaRow = $psgaStmt->get_result()->fetch_assoc();
                $psgaStmt->close();

                if (!$psgaRow) {
                    throw new Exception('PSGA not found.');
                }

                $psgaNo = trim((string)($psgaRow['PSGA_No'] ?? ''));
                if ($psgaNo === '') {
                    throw new Exception(
                        'PSGA number is missing. Agreement cannot be regenerated.'
                    );
                }

                /*
                 * Prefer the latest FSO explicitly mapped to this PSGA.
                 * If no mapping exists, fall back to the latest Offer carrying
                 * the same permanent PSGA number and create the mapping.
                 */
                $fsoStmt = $conn->prepare("
                    SELECT
                        pfm.offer_id,
                        o.offer_no,
                        o.request_id,
                        sr.request_no,
                        sr.owner_id,
                        o.psga_no
                    FROM psga_fso_map pfm
                    INNER JOIN nleta_offer_master o
                        ON o.id = pfm.offer_id
                    LEFT JOIN service_requests sr
                        ON sr.id = o.request_id
                    WHERE pfm.psga_id = ?
                    ORDER BY pfm.id DESC
                    LIMIT 1
                ");

                if (!$fsoStmt) {
                    throw new Exception(
                        'Unable to load PSGA-FSO mapping: ' . $conn->error
                    );
                }

                $fsoStmt->bind_param('i', $psgaId);
                $fsoStmt->execute();
                $fso = $fsoStmt->get_result()->fetch_assoc();
                $fsoStmt->close();

                if (!$fso) {
                    $fallbackFso = $conn->prepare("
                        SELECT
                            o.id AS offer_id,
                            o.offer_no,
                            o.request_id,
                            sr.request_no,
                            sr.owner_id,
                            o.psga_no
                        FROM nleta_offer_master o
                        LEFT JOIN service_requests sr
                            ON sr.id = o.request_id
                        WHERE o.psga_no = ?
                        ORDER BY o.id DESC
                        LIMIT 1
                    ");

                    if (!$fallbackFso) {
                        throw new Exception(
                            'Unable to find FSO for PSGA regeneration: ' . $conn->error
                        );
                    }

                    $fallbackFso->bind_param('s', $psgaNo);
                    $fallbackFso->execute();
                    $fso = $fallbackFso->get_result()->fetch_assoc();
                    $fallbackFso->close();

                    if ($fso) {
                        mapPsgaToFso(
                            $conn,
                            $psgaId,
                            (int)$fso['offer_id']
                        );
                    }
                }

                if (!$fso) {
                    throw new Exception(
                        'No FSO is mapped to PSGA ' . $psgaNo .
                        '. Regeneration cannot determine the agreement assets/services.'
                    );
                }

                $offerId = (int)($fso['offer_id'] ?? 0);
                $requestId = (int)($fso['request_id'] ?? 0);

                if ($offerId <= 0) {
                    throw new Exception(
                        'Invalid FSO mapping. Agreement cannot be regenerated.'
                    );
                }

                $assetStmt = $conn->prepare("
                    SELECT
                        oa.id AS offer_asset_id,
                        oa.asset_id AS source_asset_id,
                        a.display_asset_id,
                        a.asset_name,
                        a.location
                    FROM nleta_offer_assets oa
                    INNER JOIN assets a
                        ON a.id = oa.asset_id
                    WHERE oa.offer_id = ?
                    ORDER BY oa.id ASC
                ");

                if (!$assetStmt) {
                    throw new Exception(
                        'Unable to load FSO assets for regeneration: ' . $conn->error
                    );
                }

                $assetStmt->bind_param('i', $offerId);
                $assetStmt->execute();
                $assetResult = $assetStmt->get_result();

                $regenAssets = [];
                while ($assetRow = $assetResult->fetch_assoc()) {
                    $regenAssets[] = $assetRow;
                }
                $assetStmt->close();

                if (!$regenAssets) {
                    throw new Exception(
                        'No assets are linked to FSO ' .
                        (string)($fso['offer_no'] ?? '') .
                        '. Agreement cannot be regenerated.'
                    );
                }

                $regenServices = psgaGetOfferServices(
                    $conn,
                    $offerId,
                    $requestId,
                    0
                );

                if (!$regenServices) {
                    throw new Exception(
                        'No selected services are linked to FSO ' .
                        (string)($fso['offer_no'] ?? '') .
                        '. Agreement cannot be regenerated.'
                    );
                }

                $selectedServiceValues = [];

                foreach ($regenServices as $service) {
                    $code = trim((string)($service['service_code'] ?? ''));
                    $name = trim((string)($service['service_name'] ?? ''));

                    if ($code !== '' && $name !== '') {
                        $selectedServiceValues[] =
                            $code . ' - ' . $name;
                    } elseif ($name !== '') {
                        $selectedServiceValues[] = $name;
                    } elseif ($code !== '') {
                        $selectedServiceValues[] = $code;
                    }
                }

                $selectedServicesJson = json_encode(
                    array_values(array_unique($selectedServiceValues)),
                    JSON_UNESCAPED_UNICODE
                );

                /*
                 * Keep the permanent PSGA, but refresh the contract's
                 * current FSO/request/primary asset/service snapshot.
                 */
                $primaryAssetId = (int)(
                    $regenAssets[0]['source_asset_id'] ?? 0
                );

                $masterAssetId = 0;

                if ($primaryAssetId > 0 && tableExists($conn, 'asset_master')) {
                    $assetMasterStmt = $conn->prepare("
                        SELECT Asset_ID
                        FROM asset_master
                        WHERE BINARY Display_Asset_ID = BINARY ?
                        LIMIT 1
                    ");

                    if ($assetMasterStmt) {
                        $primaryDisplayId = trim((string)(
                            $regenAssets[0]['display_asset_id'] ?? ''
                        ));

                        if ($primaryDisplayId !== '') {
                            $assetMasterStmt->bind_param(
                                's',
                                $primaryDisplayId
                            );

                            if ($assetMasterStmt->execute()) {
                                $masterAssetRow =
                                    $assetMasterStmt->get_result()->fetch_assoc();

                                if ($masterAssetRow) {
                                    $masterAssetId =
                                        (int)($masterAssetRow['Asset_ID'] ?? 0);
                                }
                            }
                        }

                        $assetMasterStmt->close();
                    }
                }

                if ($masterAssetId <= 0) {
                    $masterAssetId = (int)($psgaRow['Asset_ID'] ?? 0);
                }

                $setParts = [];
                $types = '';
                $values = [];

                if (columnExists($conn, 'contracts', 'Request_ID')) {
                    $setParts[] = 'Request_ID = ?';
                    $types .= 'i';
                    $values[] = $requestId;
                }

                if (
                    $masterAssetId > 0 &&
                    columnExists($conn, 'contracts', 'Asset_ID')
                ) {
                    $setParts[] = 'Asset_ID = ?';
                    $types .= 'i';
                    $values[] = $masterAssetId;
                }

                if (
                    $selectedServicesJson !== false &&
                    columnExists($conn, 'contracts', 'Selected_Services')
                ) {
                    $setParts[] = 'Selected_Services = ?';
                    $types .= 's';
                    $values[] = $selectedServicesJson;
                }

                if (
                    (int)($fso['owner_id'] ?? 0) > 0 &&
                    columnExists($conn, 'contracts', 'Owner_ID')
                ) {
                    $setParts[] = 'Owner_ID = ?';
                    $types .= 'i';
                    $values[] = (int)$fso['owner_id'];
                }

                if (
                    columnExists($conn, 'contracts', 'Updated_At')
                ) {
                    $setParts[] = 'Updated_At = CURRENT_TIMESTAMP';
                }

                if ($setParts) {
                    $sql = "
                        UPDATE contracts
                        SET " . implode(', ', $setParts) . "
                        WHERE PSGA_ID = ?
                    ";

                    $types .= 'i';
                    $values[] = $psgaId;

                    $syncStmt = $conn->prepare($sql);

                    if (!$syncStmt) {
                        throw new Exception(
                            'Unable to prepare PSGA regeneration: ' . $conn->error
                        );
                    }

                    $bind = [$types];
                    foreach ($values as $i => $value) {
                        $bind[] = &$values[$i];
                    }

                    call_user_func_array(
                        [$syncStmt, 'bind_param'],
                        $bind
                    );

                    if (!$syncStmt->execute()) {
                        $syncError = $syncStmt->error;
                        $syncStmt->close();

                        throw new Exception(
                            'Unable to regenerate PSGA agreement: ' . $syncError
                        );
                    }

                    $syncStmt->close();
                }

                /* Keep the FSO permanently associated with this PSGA. */
                mapPsgaToFso(
                    $conn,
                    $psgaId,
                    $offerId
                );

                /*
                 * Keep nleta_offer_master.psga_no synchronized with the
                 * permanent PSGA number.
                 */
                $offerPsga = $conn->prepare("
                    UPDATE nleta_offer_master
                    SET psga_no = ?
                    WHERE id = ?
                ");

                if ($offerPsga) {
                    $offerPsga->bind_param(
                        'si',
                        $psgaNo,
                        $offerId
                    );

                    if (!$offerPsga->execute()) {
                        $offerError = $offerPsga->error;
                        $offerPsga->close();

                        throw new Exception(
                            'Unable to synchronize FSO with PSGA: ' . $offerError
                        );
                    }

                    $offerPsga->close();
                }

                $success =
                    'PSGA ' . $psgaNo .
                    ' regenerated successfully from FSO ' .
                    (string)($fso['offer_no'] ?? '—') .
                    '. The same permanent PSGA number was retained; no new PSGA record was created.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   GENERATE PSGA
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['generate_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $offerId = (int)($_POST['offer_id'] ?? 0);

        if ($offerId <= 0) {
            $error = 'Invalid Offer selected.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT
                        o.id AS offer_id,
                        o.offer_no,
                        o.psga_no,
                        o.request_id,
                        sr.request_no,
                        sr.owner_id,
                        u.name AS owner_name,
                        u.email AS owner_email,
                        u.phone AS owner_phone,
                        u.organization_name AS organization_name,
                        u.organization_address AS organization_address,
                        sr.service_id
                    FROM nleta_offer_master o
                    INNER JOIN service_requests sr
                        ON sr.id = o.request_id
                    LEFT JOIN users u
                        ON u.id = sr.owner_id
                    WHERE o.id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to load Offer: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $offerId);

                if (!$stmt->execute()) {
                    $stmtError = $stmt->error;
                    $stmt->close();

                    throw new Exception(
                        'Unable to load Offer: ' . $stmtError
                    );
                }

                $source = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$source) {
                    throw new Exception(
                        'Offer / Service Request not found.'
                    );
                }

                if (trim((string)$source['psga_no']) === '') {
                    throw new Exception(
                        'This Offer does not have a PSGA number.'
                    );
                }

                $verify = $conn->prepare("
                    SELECT
                        pt.Transaction_ID,
                        pt.Verified_At
                    FROM payment_transactions pt
                    INNER JOIN payment_schedule ps
                        ON ps.Schedule_ID = pt.Schedule_ID
                    WHERE pt.Offer_ID = ?
                      AND ps.Payment_Stage = 'Advance'
                      AND pt.Status = 'Verified'
                    ORDER BY pt.Transaction_ID DESC
                    LIMIT 1
                ");

                if (!$verify) {
                    throw new Exception(
                        'Unable to verify Advance payment: ' . $conn->error
                    );
                }

                $verify->bind_param('i', $offerId);

                if (!$verify->execute()) {
                    $verifyError = $verify->error;
                    $verify->close();

                    throw new Exception(
                        'Unable to verify Advance payment: ' . $verifyError
                    );
                }

                $verifiedPayment = $verify->get_result()->fetch_assoc();
                $verify->close();

                if (!$verifiedPayment) {
                    throw new Exception(
                        'PSGA can only be generated after Advance Payment is verified by Admin.'
                    );
                }

                $assetStmt = $conn->prepare("
                    SELECT
                        oa.id AS offer_asset_id,
                        oa.asset_id AS source_asset_id,
                        a.*
                    FROM nleta_offer_assets oa
                    INNER JOIN assets a
                        ON a.id = oa.asset_id
                    WHERE oa.offer_id = ?
                    ORDER BY oa.id ASC
                ");

                if (!$assetStmt) {
                    throw new Exception(
                        'Unable to load Offer assets: ' . $conn->error
                    );
                }

                $assetStmt->bind_param('i', $offerId);

                if (!$assetStmt->execute()) {
                    $assetError = $assetStmt->error;
                    $assetStmt->close();

                    throw new Exception(
                        'Unable to load Offer assets: ' . $assetError
                    );
                }

                $assetResult = $assetStmt->get_result();
                $sourceAssets = [];

                while ($assetRow = $assetResult->fetch_assoc()) {
                    $sourceAssets[] = $assetRow;
                }

                $assetStmt->close();

                if (!$sourceAssets) {
                    throw new Exception(
                        'No assets are linked to this Offer. PSGA cannot be generated.'
                    );
                }

                $representativeName = '';

                if (
                    tableExists($conn, 'asset_details') &&
                    columnExists($conn, 'asset_details', 'consignee_name')
                ) {
                    $representativeStmt = $conn->prepare("
                        SELECT consignee_name
                        FROM asset_details
                        WHERE asset_id = ?
                          AND consignee_name IS NOT NULL
                          AND TRIM(consignee_name) <> ''
                        ORDER BY id DESC
                        LIMIT 1
                    ");

                    if ($representativeStmt) {
                        foreach ($sourceAssets as $representativeAsset) {
                            $representativeAssetId =
                                (int)($representativeAsset['source_asset_id'] ?? 0);

                            if ($representativeAssetId <= 0) {
                                continue;
                            }

                            $representativeStmt->bind_param(
                                'i',
                                $representativeAssetId
                            );

                            if ($representativeStmt->execute()) {
                                $representativeRow =
                                    $representativeStmt->get_result()->fetch_assoc();

                                if ($representativeRow) {
                                    $candidateRepresentative = trim(
                                        (string)(
                                            $representativeRow['consignee_name'] ?? ''
                                        )
                                    );

                                    if ($candidateRepresentative !== '') {
                                        $representativeName =
                                            $candidateRepresentative;
                                        break;
                                    }
                                }
                            }
                        }

                        $representativeStmt->close();
                    }
                }

                if ($representativeName === '') {
                    $representativeName =
                        trim((string)($source['owner_name'] ?? ''));
                }

                $mappedAssets = [];

                $masterStmt = $conn->prepare("
                    SELECT
                        Asset_ID,
                        Display_Asset_ID,
                        Asset_Name,
                        Location,
                        Client_ID
                    FROM asset_master
                    WHERE BINARY Display_Asset_ID = BINARY ?
                    LIMIT 1
                ");

                if (!$masterStmt) {
                    throw new Exception(
                        'Unable to prepare Asset Master lookup: ' .
                        $conn->error
                    );
                }

                $autoClientId = 0;
                $ownerEmail = trim((string)($source['owner_email'] ?? ''));
                $ownerPhone = trim((string)($source['owner_phone'] ?? ''));
                $ownerName = trim((string)($source['owner_name'] ?? ''));

                $clientLookup = [];

                if ($ownerEmail !== '') {
                    $clientLookup[] = ['Email', $ownerEmail];
                }

                if ($ownerPhone !== '') {
                    $clientLookup[] = ['Mobile', $ownerPhone];
                }

                if ($ownerName !== '') {
                    $clientLookup[] = ['Organization', $ownerName];
                }

                foreach ($clientLookup as [$clientColumn, $clientValue]) {
                    if ($autoClientId > 0) {
                        break;
                    }

                    $clientSql = "
                        SELECT Client_ID
                        FROM client_master
                        WHERE `$clientColumn` = ?
                        LIMIT 1
                    ";

                    $clientStmt = $conn->prepare($clientSql);

                    if (!$clientStmt) {
                        continue;
                    }

                    $clientStmt->bind_param('s', $clientValue);

                    if ($clientStmt->execute()) {
                        $clientRow =
                            $clientStmt->get_result()->fetch_assoc();

                        if ($clientRow) {
                            $autoClientId =
                                (int)($clientRow['Client_ID'] ?? 0);
                        }
                    }

                    $clientStmt->close();
                }

                foreach ($sourceAssets as $sa) {
                    $sourceAssetId =
                        (int)($sa['source_asset_id'] ?? $sa['id'] ?? 0);

                    $displayId =
                        trim((string)($sa['display_asset_id'] ?? ''));

                    $master = null;

                    if ($displayId !== '') {
                        $masterStmt->bind_param('s', $displayId);

                        if (!$masterStmt->execute()) {
                            $mapError = $masterStmt->error;
                            $masterStmt->close();

                            throw new Exception(
                                'Unable to check Asset Master for ' .
                                ($displayId ?: ('Asset #' . $sourceAssetId)) .
                                ': ' . $mapError
                            );
                        }

                        $master =
                            $masterStmt->get_result()->fetch_assoc();
                    }

                    if (!$master) {
                        $assetName = trim((string)(
                            $sa['asset_name']
                            ?? $sa['Asset_Name']
                            ?? $displayId
                            ?? ('Asset #' . $sourceAssetId)
                        ));

                        if ($assetName === '') {
                            $assetName = 'Asset #' . $sourceAssetId;
                        }

                        $assetLocation = trim((string)(
                            $sa['location']
                            ?? $sa['Location']
                            ?? ''
                        ));

                        if ($autoClientId > 0) {
                            $insertMaster = $conn->prepare("
                                INSERT INTO asset_master
                                (
                                    Display_Asset_ID,
                                    Asset_Name,
                                    Location,
                                    Client_ID,
                                    Status
                                )
                                VALUES (?, ?, ?, ?, 'Active')
                            ");
                        } else {
                            $insertMaster = $conn->prepare("
                                INSERT INTO asset_master
                                (
                                    Display_Asset_ID,
                                    Asset_Name,
                                    Location,
                                    Client_ID,
                                    Status
                                )
                                VALUES (?, ?, ?, NULL, 'Active')
                            ");
                        }

                        if (!$insertMaster) {
                            $masterStmt->close();

                            throw new Exception(
                                'Unable to prepare automatic Asset Master creation for ' .
                                ($displayId ?: ('Asset #' . $sourceAssetId)) .
                                ': ' . $conn->error
                            );
                        }

                        if ($autoClientId > 0) {
                            $insertMaster->bind_param(
                                'sssi',
                                $displayId,
                                $assetName,
                                $assetLocation,
                                $autoClientId
                            );
                        } else {
                            $insertMaster->bind_param(
                                'sss',
                                $displayId,
                                $assetName,
                                $assetLocation
                            );
                        }

                        if (!$insertMaster->execute()) {
                            $insertError = $insertMaster->error;
                            $insertMaster->close();
                            $masterStmt->close();

                            throw new Exception(
                                'Unable to automatically add ' .
                                ($displayId ?: ('Asset #' . $sourceAssetId)) .
                                ' to Asset Master: ' . $insertError
                            );
                        }

                        $newMasterId =
                            (int)$insertMaster->insert_id;

                        $insertMaster->close();

                        if ($newMasterId <= 0) {
                            $masterStmt->close();

                            throw new Exception(
                                'Asset Master record was not created for ' .
                                ($displayId ?: ('Asset #' . $sourceAssetId))
                            );
                        }

                        $master = [
                            'Asset_ID' => $newMasterId,
                            'Display_Asset_ID' => $displayId,
                            'Asset_Name' => $assetName,
                            'Location' => $assetLocation,
                            'Client_ID' => $autoClientId
                        ];
                    }

                    $mappedAssets[] = [
                        'source_asset_id' => $sourceAssetId,
                        'display_asset_id' => $displayId !== ''
                            ? $displayId
                            : (string)($master['Display_Asset_ID'] ?? ''),
                        'asset_name' => (string)(
                            $sa['asset_name']
                            ?? $sa['Asset_Name']
                            ?? $master['Asset_Name']
                            ?? ''
                        ),
                        'location' => (string)(
                            $sa['location']
                            ?? $sa['Location']
                            ?? $master['Location']
                            ?? ''
                        ),
                        'master_asset_id' =>
                            (int)$master['Asset_ID'],
                        'client_id' =>
                            (int)($master['Client_ID'] ?? $autoClientId ?? 0)
                    ];
                }

                $masterStmt->close();

                if (!$mappedAssets) {
                    throw new Exception(
                        'No valid Asset Master mapping found.'
                    );
                }

                $existing = $conn->prepare("
                    SELECT PSGA_ID, PSGA_No, Status
                    FROM contracts
                    WHERE Owner_ID = ?
                    ORDER BY PSGA_ID ASC
                    LIMIT 1
                ");

                if (!$existing) {
                    throw new Exception(
                        'Unable to check existing permanent PSGA for Owner: ' .
                        $conn->error
                    );
                }

                $sourceOwnerId =
                    (int)($source['owner_id'] ?? 0);

                if ($sourceOwnerId <= 0) {
                    $existing->close();

                    throw new Exception(
                        'Owner ID is missing. Permanent PSGA cannot be resolved.'
                    );
                }

                $existing->bind_param('i', $sourceOwnerId);
                $existing->execute();

                $existingRow =
                    $existing->get_result()->fetch_assoc();

                $existing->close();

                if ($existingRow) {
                    $permanentPsgaId =
                        (int)$existingRow['PSGA_ID'];

                    $permanentPsgaNo =
                        trim((string)$existingRow['PSGA_No']);

                    if ($permanentPsgaNo === '') {
                        throw new Exception(
                            'Existing permanent PSGA has no PSGA number. New FSO was not attached.'
                        );
                    }

                    /*
                     * Reuse the permanent PSGA but still register THIS
                     * NEW FSO against it. This is what makes a new FSO
                     * visible in the PSGA register.
                     */
                    mapPsgaToFso(
                        $conn,
                        $permanentPsgaId,
                        $offerId
                    );

                    $offerPsgaUpdate = $conn->prepare("
                        UPDATE nleta_offer_master
                        SET psga_no = ?
                        WHERE id = ?
                    ");

                    if (!$offerPsgaUpdate) {
                        throw new Exception(
                            'Unable to attach FSO to existing permanent PSGA: ' .
                            $conn->error
                        );
                    }

                    $offerPsgaUpdate->bind_param(
                        'si',
                        $permanentPsgaNo,
                        $offerId
                    );

                    if (!$offerPsgaUpdate->execute()) {
                        $offerPsgaError =
                            $offerPsgaUpdate->error;

                        $offerPsgaUpdate->close();

                        throw new Exception(
                            'Unable to attach FSO to existing permanent PSGA: ' .
                            $offerPsgaError
                        );
                    }

                    $offerPsgaUpdate->close();

                    if (
                        tableExists($conn, 'payments') &&
                        columnExists($conn, 'payments', 'PSGA_ID')
                    ) {
                        $link1 = $conn->prepare("
                            UPDATE payments
                            SET PSGA_ID = ?
                            WHERE Offer_ID = ?
                        ");

                        if ($link1) {
                            $link1->bind_param(
                                'ii',
                                $permanentPsgaId,
                                $offerId
                            );

                            if (!$link1->execute()) {
                                throw new Exception(
                                    'Unable to link payment to permanent PSGA: ' .
                                    $link1->error
                                );
                            }

                            $link1->close();
                        }
                    }

                    if (
                        tableExists($conn, 'payment_schedule') &&
                        columnExists($conn, 'payment_schedule', 'PSGA_ID')
                    ) {
                        $link2 = $conn->prepare("
                            UPDATE payment_schedule
                            SET PSGA_ID = ?
                            WHERE Offer_ID = ?
                        ");

                        if ($link2) {
                            $link2->bind_param(
                                'ii',
                                $permanentPsgaId,
                                $offerId
                            );

                            if (!$link2->execute()) {
                                throw new Exception(
                                    'Unable to link payment schedule to permanent PSGA: ' .
                                    $link2->error
                                );
                            }

                            $link2->close();
                        }
                    }

                    if (
                        tableExists($conn, 'payment_transactions') &&
                        columnExists($conn, 'payment_transactions', 'PSGA_ID')
                    ) {
                        $link3 = $conn->prepare("
                            UPDATE payment_transactions
                            SET PSGA_ID = ?
                            WHERE Offer_ID = ?
                        ");

                        if ($link3) {
                            $link3->bind_param(
                                'ii',
                                $permanentPsgaId,
                                $offerId
                            );

                            if (!$link3->execute()) {
                                throw new Exception(
                                    'Unable to link payment transaction to permanent PSGA: ' .
                                    $link3->error
                                );
                            }

                            $link3->close();
                        }
                    }

                    $success =
                        'Permanent PSGA ' . $permanentPsgaNo .
                        ' already exists for this Owner and has been reused for FSO ' .
                        trim((string)$source['offer_no']) .
                        '. No new PSGA was created.';
                } else {
                    $primaryMasterAssetId =
                        (int)$mappedAssets[0]['master_asset_id'];

                    $generationServices = psgaGetOfferServices(
                        $conn,
                        $offerId,
                        (int)($source['request_id'] ?? 0),
                        (int)($source['service_id'] ?? 0)
                    );

                    if (!$generationServices) {
                        throw new Exception(
                            'No selected services were found for this Offer. PSGA cannot be generated.'
                        );
                    }

                    $selectedServices = json_encode(
                        array_values(
                            array_unique(
                                array_map(
                                    static function (array $service): string {
                                        $code = trim(
                                            (string)($service['service_code'] ?? '')
                                        );

                                        $name = trim(
                                            (string)($service['service_name'] ?? '')
                                        );

                                        if ($code !== '' && $name !== '') {
                                            return $code . ' - ' . $name;
                                        }

                                        return $name !== ''
                                            ? $name
                                            : $code;
                                    },
                                    $generationServices
                                )
                            )
                        ),
                        JSON_UNESCAPED_UNICODE
                    );

                    $contractDate = (new DateTime(
                        'now',
                        new DateTimeZone('Asia/Kolkata')
                    ))->format('Y-m-d');

                    $conn->begin_transaction();

                    try {
                        $fields = [
                            'PSGA_No',
                            'Asset_ID',
                            'Contract_Date',
                            'Status'
                        ];

                        $values = [
                            (string)$source['psga_no'],
                            $primaryMasterAssetId,
                            $contractDate,
                            'Pending Admin Review'
                        ];

                        $optional = [
                            'Request_ID' =>
                                (int)$source['request_id'],
                            'Owner_ID' =>
                                (int)$source['owner_id'],
                            'Owner_Name' =>
                                (string)($source['owner_name'] ?? ''),
                            'Owner_Contact' =>
                                (string)($source['owner_phone'] ?? ''),
                            'Authorized_Representative' =>
                                $representativeName,
                            'Selected_Services' =>
                                $selectedServices
                        ];

                        foreach ($optional as $field => $value) {
                            if (
                                columnExists(
                                    $conn,
                                    'contracts',
                                    $field
                                )
                            ) {
                                $fields[] = $field;
                                $values[] = $value;
                            }
                        }

                        $sql =
                            'INSERT INTO contracts (`' .
                            implode('`,`', $fields) .
                            '`) VALUES (' .
                            implode(
                                ',',
                                array_fill(
                                    0,
                                    count($fields),
                                    '?'
                                )
                            ) .
                            ')';

                        $insert = $conn->prepare($sql);

                        if (!$insert) {
                            throw new Exception(
                                'Unable to prepare PSGA creation: ' .
                                $conn->error
                            );
                        }

                        $types = '';

                        foreach ($values as $value) {
                            $types .= is_int($value)
                                ? 'i'
                                : 's';
                        }

                        $bind = [$types];

                        foreach ($values as $i => $value) {
                            $bind[] = &$values[$i];
                        }

                        call_user_func_array(
                            [$insert, 'bind_param'],
                            $bind
                        );

                        if (!$insert->execute()) {
                            throw new Exception(
                                'Unable to create PSGA: ' .
                                $insert->error
                            );
                        }

                        $psgaId =
                            (int)$insert->insert_id;

                        $insert->close();

                        if ($psgaId <= 0) {
                            throw new Exception(
                                'PSGA record was created but no PSGA_ID was returned.'
                            );
                        }

                        /*
                         * Register the NEW FSO against the NEW PSGA.
                         */
                        mapPsgaToFso(
                            $conn,
                            $psgaId,
                            $offerId
                        );

                        if (
                            tableExists($conn, 'payments') &&
                            columnExists($conn, 'payments', 'PSGA_ID')
                        ) {
                            $link1 = $conn->prepare("
                                UPDATE payments
                                SET PSGA_ID = ?
                                WHERE Offer_ID = ?
                            ");

                            if ($link1) {
                                $link1->bind_param(
                                    'ii',
                                    $psgaId,
                                    $offerId
                                );

                                if (!$link1->execute()) {
                                    throw new Exception(
                                        'Unable to link payment to PSGA: ' .
                                        $link1->error
                                    );
                                }

                                $link1->close();
                            }
                        }

                        if (
                            tableExists($conn, 'payment_schedule') &&
                            columnExists($conn, 'payment_schedule', 'PSGA_ID')
                        ) {
                            $link2 = $conn->prepare("
                                UPDATE payment_schedule
                                SET PSGA_ID = ?
                                WHERE Offer_ID = ?
                            ");

                            if ($link2) {
                                $link2->bind_param(
                                    'ii',
                                    $psgaId,
                                    $offerId
                                );

                                if (!$link2->execute()) {
                                    throw new Exception(
                                        'Unable to link payment schedule to PSGA: ' .
                                        $link2->error
                                    );
                                }

                                $link2->close();
                            }
                        }

                        if (
                            tableExists($conn, 'payment_transactions') &&
                            columnExists($conn, 'payment_transactions', 'PSGA_ID')
                        ) {
                            $link3 = $conn->prepare("
                                UPDATE payment_transactions
                                SET PSGA_ID = ?
                                WHERE Offer_ID = ?
                            ");

                            if ($link3) {
                                $link3->bind_param(
                                    'ii',
                                    $psgaId,
                                    $offerId
                                );

                                if (!$link3->execute()) {
                                    throw new Exception(
                                        'Unable to link payment transaction to PSGA: ' .
                                        $link3->error
                                    );
                                }

                                $link3->close();
                            }
                        }

                        $conn->commit();

                        $success =
                            'PSGA ' . $source['psga_no'] .
                            ' generated successfully for ' .
                            count($mappedAssets) .
                            ' asset(s). Admin must View / Print it and then click Send to Owner.';
                    } catch (Throwable $inner) {
                        $conn->rollback();
                        throw $inner;
                    }
                }
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   ADMIN VIEW
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['view_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $psgaId = (int)($_POST['psga_id'] ?? 0);

        if ($psgaId <= 0) {
            $error = 'Invalid PSGA selected.';
        } else {
            if (columnExists($conn, 'contracts', 'Admin_Viewed_At')) {
                $stmt = $conn->prepare("
                    UPDATE contracts
                    SET Admin_Viewed_At = NOW()
                    WHERE PSGA_ID = ?
                ");

                if ($stmt) {
                    $stmt->bind_param('i', $psgaId);

                    if ($stmt->execute()) {
                        $_SESSION['psga_admin_viewed'][$psgaId] = true;
                        $success =
                            'PSGA marked as viewed by Admin. You can now send it to Owner.';
                    } else {
                        $error =
                            'Unable to record Admin view: ' .
                            $stmt->error;
                    }

                    $stmt->close();
                } else {
                    $error =
                        'Unable to prepare Admin view: ' .
                        $conn->error;
                }
            } else {
                $_SESSION['psga_admin_viewed'][$psgaId] = true;
                $success =
                    'PSGA opened for Admin review. You can now send it to Owner.';
            }
        }
    }
}

/* =========================================================
   SEND PSGA TO OWNER
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['send_psga_owner'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $psgaId = (int)($_POST['psga_id'] ?? 0);

        if ($psgaId <= 0) {
            $error = 'Invalid PSGA selected.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT
                        c.PSGA_ID,
                        c.PSGA_No,
                        c.Status,
                        c.Owner_Name,
                        c.Owner_ID,
                        c.Request_ID,
                        o.offer_no,
                        sr.request_no,
                        u.name AS owner_name,
                        u.email AS owner_email
                    FROM contracts c
                    LEFT JOIN nleta_offer_master o
                        ON o.psga_no = c.PSGA_No
                    LEFT JOIN service_requests sr
                        ON sr.id = c.Request_ID
                    LEFT JOIN users u
                        ON u.id = c.Owner_ID
                    WHERE c.PSGA_ID = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to load PSGA: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $psgaId);

                if (!$stmt->execute()) {
                    $sendLoadError = $stmt->error;
                    $stmt->close();

                    throw new Exception(
                        'Unable to load PSGA: ' . $sendLoadError
                    );
                }

                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$row) {
                    throw new Exception('PSGA not found.');
                }

                $adminViewed = false;

                if (columnExists($conn, 'contracts', 'Admin_Viewed_At')) {
                    $viewCheck = $conn->prepare("
                        SELECT Admin_Viewed_At
                        FROM contracts
                        WHERE PSGA_ID = ?
                        LIMIT 1
                    ");

                    if ($viewCheck) {
                        $viewCheck->bind_param('i', $psgaId);

                        if ($viewCheck->execute()) {
                            $viewRow =
                                $viewCheck->get_result()->fetch_assoc();

                            $adminViewed =
                                !empty($viewRow['Admin_Viewed_At']);
                        }

                        $viewCheck->close();
                    }
                }

                if (!$adminViewed) {
                    $adminViewed =
                        !empty(
                            $_SESSION['psga_admin_viewed'][$psgaId]
                        );
                }

                if (!$adminViewed) {
                    throw new Exception(
                        'Admin must View the PSGA before sending it to Owner.'
                    );
                }

                $psgaStatus =
                    trim((string)($row['Status'] ?? ''));

                $psgaStatusKey =
                    strtolower(
                        preg_replace(
                            '/\s+/',
                            ' ',
                            $psgaStatus
                        )
                    );

                $sendAllowedStatuses = [
                    'pending admin review',
                    'pending_admin_review',
                    'admin review',
                    'pending review',
                    ''
                ];

                if (
                    !in_array(
                        $psgaStatusKey,
                        $sendAllowedStatuses,
                        true
                    )
                ) {
                    throw new Exception(
                        'This PSGA is currently in status: ' .
                        ($psgaStatus !== ''
                            ? $psgaStatus
                            : 'Pending Admin Review') .
                        '. Only a PSGA waiting for Admin review can be sent to Owner.'
                    );
                }

                if (
                    trim((string)$row['owner_email']) === ''
                ) {
                    throw new Exception(
                        'Owner email address is missing. PSGA was not sent.'
                    );
                }

                $mailerFile =
                    __DIR__ . '/../config/nleta-mailer.php';

                if (!file_exists($mailerFile)) {
                    throw new Exception(
                        'NLETA mailer file not found.'
                    );
                }

                require_once $mailerFile;

                if (
                    !function_exists(
                        'sendNletaPsgaOwnerEmail'
                    )
                ) {
                    throw new Exception(
                        'PSGA Owner email function is missing in nleta-mailer.php.'
                    );
                }

                $sent = sendNletaPsgaOwnerEmail(
                    (int)$row['PSGA_ID'],
                    (string)$row['PSGA_No'],
                    (string)(
                        $row['owner_name']
                        ?: $row['Owner_Name']
                        ?: 'Owner'
                    ),
                    (string)$row['owner_email'],
                    (string)($row['offer_no'] ?? ''),
                    (string)($row['request_no'] ?? '')
                );

                if (!$sent) {
                    throw new Exception(
                        'PSGA email could not be sent. Status was not changed.'
                    );
                }

                $up = $conn->prepare("
                    UPDATE contracts
                    SET Status = 'Pending Owner Confirmation'
                    WHERE PSGA_ID = ?
                      AND (
                          Status IS NULL
                          OR TRIM(Status) = ''
                          OR LOWER(TRIM(Status)) IN (
                              'pending admin review',
                              'pending_admin_review',
                              'admin review',
                              'pending review'
                          )
                      )
                ");

                if (!$up) {
                    throw new Exception(
                        'Unable to prepare PSGA status update: ' .
                        $conn->error
                    );
                }

                $up->bind_param('i', $psgaId);

                if (
                    !$up->execute() ||
                    $up->affected_rows !== 1
                ) {
                    $err =
                        $up->error
                        ?: 'PSGA status was not updated.';

                    $up->close();

                    throw new Exception($err);
                }

                $up->close();

                unset(
                    $_SESSION['psga_admin_viewed'][$psgaId]
                );

                $success =
                    'PSGA ' . $row['PSGA_No'] .
                    ' has been sent to Owner for confirmation.';
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   ADMIN CONFIRMATION
========================================================= */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['confirm_psga'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $psgaId = (int)($_POST['psga_id'] ?? 0);

        if ($psgaId <= 0) {
            $error = 'Invalid PSGA selected.';
        } else {
            $setParts = ["Status = 'Confirmed'"];

            if (
                columnExists(
                    $conn,
                    'contracts',
                    'Admin_Confirmed_At'
                )
            ) {
                $setParts[] =
                    "Admin_Confirmed_At = NOW()";
            }

            if (
                columnExists(
                    $conn,
                    'contracts',
                    'Updated_At'
                )
            ) {
                $setParts[] =
                    "Updated_At = CURRENT_TIMESTAMP";
            }

            $sql = "
                UPDATE contracts
                SET " . implode(', ', $setParts) . "
                WHERE PSGA_ID = ?
                  AND Status = 'Pending Admin Confirmation'
            ";

            $stmt = $conn->prepare($sql);

            if ($stmt) {
                $stmt->bind_param('i', $psgaId);

                if (
                    $stmt->execute() &&
                    $stmt->affected_rows === 1
                ) {
                    $success =
                        'PSGA confirmed successfully. SAF is now allowed to open/generate.';
                } else {
                    $error =
                        'PSGA could not be confirmed. It must be in Pending Admin Confirmation.';
                }

                $stmt->close();
            } else {
                $error =
                    'Unable to confirm PSGA: ' .
                    $conn->error;
            }
        }
    }
}

/* =========================================================
   OFFER ELIGIBILITY
   ---------------------------------------------------------
   Show every verified-Advance FSO that has NOT yet been
   processed through PSGA generation. Existing PSGA records
   do not hide a NEW FSO.
========================================================= */
$eligibleOffers = [];

$eligibleSql = "
    SELECT
        MIN(o.id) AS offer_id,
        MIN(o.offer_no) AS offer_no,
        o.psga_no,
        MIN(o.request_id) AS request_id,
        MIN(sr.request_no) AS request_no,
        sr.owner_id,
        MAX(u.name) AS owner_name,
        MAX(u.email) AS owner_email,

        COALESCE(
            (
                SELECT GROUP_CONCAT(
                    DISTINCT CONCAT(
                        COALESCE(sc2.service_code, ''),
                        CASE
                            WHEN sc2.service_code IS NOT NULL
                                 AND sc2.service_code <> ''
                                 AND sc2.service_name IS NOT NULL
                                 AND sc2.service_name <> ''
                            THEN ' - '
                            ELSE ''
                        END,
                        COALESCE(sc2.service_name, '')
                    )
                    ORDER BY srs2.id ASC
                    SEPARATOR ' | '
                )
                FROM service_request_services srs2
                INNER JOIN service_catalog sc2
                    ON sc2.id = srs2.service_id
                WHERE srs2.request_id = sr.id
            ),
            CONCAT('Service ID ', sr.service_id)
        ) AS service_name,

        MAX(pt.Verified_At) AS advance_verified_at

    FROM nleta_offer_master o

    INNER JOIN service_requests sr
        ON sr.id = o.request_id

    LEFT JOIN users u
        ON u.id = sr.owner_id

    INNER JOIN payment_transactions pt
        ON pt.Offer_ID = o.id

    INNER JOIN payment_schedule ps
        ON ps.Schedule_ID = pt.Schedule_ID
       AND ps.Payment_Stage = 'Advance'

    WHERE pt.Status = 'Verified'

      AND NOT EXISTS (
          SELECT 1
          FROM psga_fso_map pf0
          WHERE pf0.offer_id = o.id
      )

    GROUP BY
        o.psga_no,
        sr.owner_id

    ORDER BY MAX(o.id) DESC
";

$result = $conn->query($eligibleSql);

if ($result) {
    while ($r = $result->fetch_assoc()) {
        $eligibleOffers[] = $r;
    }

    $result->free();
}

/* =========================================================
   PSGA LIST
   ---------------------------------------------------------
   The register resolves the latest FSO mapped to each PSGA.
   NEW FSO records are mapped during generation, so their FSO,
   services and assets remain visible even when the permanent
   PSGA already existed.
========================================================= */
$psgas = [];

$listSql = "
    SELECT
        c.*,

        o.id AS fso_offer_id,
        o.offer_no AS fso_offer_no,
        o.request_id AS fso_request_id,

        sr.request_no AS fso_request_no,

        COALESCE(
            c.Owner_Name,
            u.name
        ) AS display_owner,

        COALESCE(
            (
                SELECT ad.consignee_name
                FROM nleta_offer_assets oa_rep
                INNER JOIN assets a_rep
                    ON a_rep.id = oa_rep.asset_id
                INNER JOIN asset_details ad
                    ON ad.asset_id = a_rep.id
                WHERE oa_rep.offer_id = o.id
                  AND ad.consignee_name IS NOT NULL
                  AND TRIM(ad.consignee_name) <> ''
                ORDER BY
                    oa_rep.id ASC,
                    ad.id DESC
                LIMIT 1
            ),
            NULLIF(c.Authorized_Representative, ''),
            c.Owner_Name,
            u.name
        ) AS display_representative,

        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    COALESCE(oi.service_code, ''),
                    '||',
                    COALESCE(oi.service_name, '')
                )
                ORDER BY oi.id ASC
                SEPARATOR '|||SERVICE|||'
            )
            FROM nleta_offer_items oi
            WHERE oi.offer_id = o.id
        ) AS fso_services,

        u.email AS owner_email,
        u.organization_name AS organization_name,
        u.organization_address AS organization_address,

        am_primary.Asset_Name AS asset_name,
        am_primary.Display_Asset_ID AS display_asset_id,
        am_primary.Location AS location,

        (
            SELECT GROUP_CONCAT(
                CONCAT(
                    COALESCE(sc3.service_code, ''),
                    '||',
                    COALESCE(sc3.service_name, '')
                )
                ORDER BY srs3.id ASC
                SEPARATOR '|||SERVICE|||'
            )
            FROM service_request_services srs3
            INNER JOIN service_catalog sc3
                ON sc3.id = srs3.service_id
            WHERE srs3.request_id = COALESCE(
                o.request_id,
                c.Request_ID
            )
        ) AS request_services,

        (
            SELECT GROUP_CONCAT(
                DISTINCT a2.display_asset_id
                ORDER BY oa2.id
                SEPARATOR '||'
            )
            FROM nleta_offer_assets oa2
            INNER JOIN assets a2
                ON a2.id = oa2.asset_id
            WHERE oa2.offer_id = o.id
              AND a2.display_asset_id IS NOT NULL
              AND TRIM(a2.display_asset_id) <> ''
        ) AS all_assets

    FROM contracts c

    LEFT JOIN users u
        ON u.id = c.Owner_ID

    LEFT JOIN asset_master am_primary
        ON am_primary.Asset_ID = c.Asset_ID

    LEFT JOIN psga_fso_map pfm
        ON pfm.id = (
            SELECT MAX(pfm2.id)
            FROM psga_fso_map pfm2
            WHERE pfm2.psga_id = c.PSGA_ID
        )

    LEFT JOIN nleta_offer_master o
        ON o.id = COALESCE(
            pfm.offer_id,
            (
                SELECT MAX(o2.id)
                FROM nleta_offer_master o2
                WHERE o2.psga_no = c.PSGA_No
            )
        )

    LEFT JOIN service_requests sr
        ON sr.id = COALESCE(
            o.request_id,
            c.Request_ID
        )

    WHERE c.PSGA_ID = (
        SELECT MIN(c2.PSGA_ID)
        FROM contracts c2
        WHERE c2.PSGA_No = c.PSGA_No
    )

    ORDER BY
        c.PSGA_ID DESC,
        o.id DESC
";

$result = $conn->query($listSql);

if (!$result) {
    $error = 'Unable to load PSGA list: ' . $conn->error;
} else {
    while ($r = $result->fetch_assoc()) {
        $psgas[] = $r;
    }

    $result->free();
}

/* =========================================================
   NORMALIZE REGISTER DATA
========================================================= */
foreach ($psgas as &$psgaRow) {
    $psgaRow['display_association_name'] =
        trim((string)(
            $psgaRow['organization_name'] ?? ''
        ));

    $psgaRow['display_association_address'] =
        trim((string)(
            $psgaRow['organization_address'] ?? ''
        ));

    if (
        trim((string)($psgaRow['Status'] ?? '')) === ''
    ) {
        $psgaRow['Status'] =
            'Pending Admin Review';

        $fix = $conn->prepare("
            UPDATE contracts
            SET Status = 'Pending Admin Review'
            WHERE PSGA_ID = ?
              AND (
                  Status IS NULL
                  OR TRIM(Status) = ''
              )
        ");

        if ($fix) {
            $fix->bind_param(
                'i',
                $psgaRow['PSGA_ID']
            );
            $fix->execute();
            $fix->close();
        }
    }

    /*
     * IMPORTANT:
     * Load assets from ALL FSOs belonging to this permanent PSGA.
     * The old logic used only the latest FSO, which caused the PSGA
     * document to show only one FSO's assets.
     */
    $psgaRow['asset_items'] = psgaGetAllFsoAssets(
        $conn,
        (int)($psgaRow['PSGA_ID'] ?? 0),
        trim((string)($psgaRow['PSGA_No'] ?? ''))
    );

    /*
     * Keep the existing fallback for old/legacy PSGAs that do not
     * have an FSO asset link available.
     */
    if (!$psgaRow['asset_items']) {
        $fallback = trim(
            (string)(
                $psgaRow['display_asset_id']
                ?: $psgaRow['asset_name']
                ?: ''
            )
        );

        if ($fallback !== '') {
            $psgaRow['asset_items'][] =
                $fallback;
        }
    }

    $psgaRow['approved_service_codes'] = [];
    $psgaRow['approved_service_names'] = [];
    $psgaRow['psga_service_names'] = '';

    /*
     * Service display source.
     *
     * Phase-4 service_request_services is the canonical source because it
     * contains one row per selected service. FSO items can contain multiple
     * priced lines for the same service (for example one PAT line per asset),
     * so using fso_services directly can duplicate / vary the service text.
     *
     * We therefore prefer request_services and use fso_services only as a
     * fallback for legacy records where the Phase-4 relation is unavailable.
     */
    $serviceSource = '';
    if (!empty($psgaRow['request_services'])) {
        $serviceSource = (string)$psgaRow['request_services'];
    } elseif (!empty($psgaRow['fso_services'])) {
        $serviceSource = (string)$psgaRow['fso_services'];
    }

    if ($serviceSource !== '') {
        $serviceParts = explode('|||SERVICE|||', $serviceSource);

        foreach ($serviceParts as $servicePart) {
            $servicePart = trim($servicePart);
            if ($servicePart === '') {
                continue;
            }

            $pieces = explode('||', $servicePart, 2);
            $code = strtoupper(trim((string)($pieces[0] ?? '')));
            $name = trim((string)($pieces[1] ?? ''));

            if ($code !== '') {
                $psgaRow['approved_service_codes'][] = $code;
            }
            if ($name !== '') {
                $psgaRow['approved_service_names'][] = $name;
            }
        }
    }

    $psgaRow['approved_service_codes'] = array_values(
        array_unique($psgaRow['approved_service_codes'])
    );
    $psgaRow['approved_service_names'] = array_values(
        array_unique($psgaRow['approved_service_names'])
    );

    /*
     * Legacy PSGA fallback.
     */
    if (
        !$psgaRow['approved_service_codes'] &&
        !empty($psgaRow['Selected_Services'])
    ) {
        $legacySelected =
            strtolower(
                trim(
                    (string)$psgaRow['Selected_Services']
                )
            );

        if (strpos($legacySelected, 'pat') !== false) {
            $psgaRow['approved_service_codes'][] =
                'PAT';
        }

        if (strpos($legacySelected, 'sat') !== false) {
            $psgaRow['approved_service_codes'][] =
                'SAT';
        }

        if (
            strpos($legacySelected, 'sci') !== false ||
            strpos(
                $legacySelected,
                'standards compliance'
            ) !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'SCI';
        }

        if (
            strpos($legacySelected, 'uap') !== false ||
            strpos($legacySelected, 'awareness') !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'UAP';
        }

        if (
            strpos($legacySelected, 'mock') !== false ||
            strpos($legacySelected, 'drill') !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'MOCK';
        }

        if (
            strpos($legacySelected, 'qrt') !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'QRT';
        }

        if (
            strpos($legacySelected, 'digital') !== false ||
            strpos($legacySelected, 'governance') !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'DG';
        }

        if (
            strpos($legacySelected, 'iegs') !== false ||
            strpos(
                $legacySelected,
                'integrated engineering'
            ) !== false
        ) {
            $psgaRow['approved_service_codes'][] =
                'IEGS';
        }

        $psgaRow['approved_service_codes'] =
            array_values(
                array_unique(
                    $psgaRow['approved_service_codes']
                )
            );
    }

    /*
     * Build a simple service display value.
     * Show each selected service only once and never repeat it for every asset.
     * Example: PAT - Partial Acceptance Test, DG - Digital Governance Support.
     */
    $combinedServiceNames = [];
    $serviceCount = max(
        count($psgaRow['approved_service_codes']),
        count($psgaRow['approved_service_names'])
    );

    for ($si = 0; $si < $serviceCount; $si++) {
        $code = trim((string)($psgaRow['approved_service_codes'][$si] ?? ''));
        $name = trim((string)($psgaRow['approved_service_names'][$si] ?? ''));

        if ($code !== '' && $name !== '') {
            $combinedServiceNames[] = $code . ' - ' . $name;
        } elseif ($name !== '') {
            $combinedServiceNames[] = $name;
        } elseif ($code !== '') {
            $combinedServiceNames[] = $code;
        }
    }

    $psgaRow['psga_service_names'] = implode(
        ', ',
        array_values(array_unique($combinedServiceNames))
    );
}

unset($psgaRow);

/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');
?>

<style>
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
.s-review{background:#e2e3ff;color:#3730a3}
.s-admin{background:#cff4fc;color:#055160}
.s-confirmed{background:#d1e7dd;color:#0f5132}
.s-rejected{background:#f8d7da;color:#842029}
.s-other{background:#e2e3e5;color:#41464b}
.stat{background:#fff;border-radius:14px;padding:18px;box-shadow:0 2px 10px rgba(0,0,0,.07)}
.stat-label{font-size:12px;color:#6c757d}
.stat-value{font-size:26px;font-weight:700}
.modal-info{background:#f8f9fa;border:1px solid #e5e7eb;border-radius:10px;padding:12px}
.psga-print-source{display:none}
.psga-paper-wrap{padding:20px;margin-top:20px}
.psga-page-paper{
    width:210mm;min-height:297mm;margin:0 auto 20px;background:#fff;
    box-sizing:border-box;padding:16mm 16mm 17mm;position:relative;
    border:1px solid #cfd5dc;color:#15191d;font-family:Arial,Helvetica,sans-serif;font-size:10.8pt;line-height:1.42;
    box-shadow:0 3px 14px rgba(0,0,0,.08)
}
.psga-page-paper h1{text-align:center;font-size:17pt;letter-spacing:.35px;margin:4px 0 5px;font-weight:800;color:#111}
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
.psga-page-paper p{margin:6px 0}
.psga-page-paper .center{text-align:center}
.psga-page-paper .footer{position:absolute;bottom:7mm;left:0;right:0;text-align:center;font-size:9pt}
.psga-page-paper .rule{border-bottom:1px solid #111;display:inline-block;min-width:230px}
.psga-services{margin-top:6px}
.psga-services div{margin:5px 0}
.psga-table-doc{width:100%;border-collapse:collapse;margin-top:8px}
.psga-table-doc th,.psga-table-doc td{border:1px solid #555;padding:6px 7px;vertical-align:top}
.psga-table-doc th{background:#f2f4f6;font-weight:700;text-align:center}
.psga-signatures{display:grid;grid-template-columns:1fr 1fr;gap:35px;margin-top:20px}
.psga-signatures .line{border-bottom:1px solid #111;min-height:28px;margin-bottom:8px}
.psga-watermark{position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:112mm;opacity:.055;pointer-events:none;z-index:0;text-align:center}
.psga-watermark img{width:100%;height:auto;display:block;object-fit:contain}
.print-btn{margin-bottom:10px}
.btn-outline-danger{font-weight:600}
.service-list-badge{display:inline-block;margin:0;padding:5px 8px;border-radius:6px;background:#eaf2ff;color:#0d6efd;border:1px solid #cfe0ff;font-size:11px;font-weight:700}
.psga-review-pages{background:#eef1f5;padding:20px}
.psga-review-pages .psga-page-paper{margin:0 auto 20px;border:1px solid #d7dce1;box-shadow:0 3px 12px rgba(0,0,0,.10)}
.psga-review-pages .psga-page-paper:last-child{margin-bottom:0}
.psga-review-pages .psga-watermark{display:block}
@media(max-width:900px){
    .psga-review-pages{padding:8px}
    .psga-review-pages .psga-page-paper{width:100%;min-height:auto;padding:24px 18px 45px;font-size:10.5pt}
}
@media(max-width:900px){
    .psga-page{padding:12px}
    .psga-card{overflow-x:auto}
    .psga-table{min-width:1100px}
    .psga-paper-wrap{overflow:auto}
}
.psga-page,.psga-header,.psga-card,.psga-paper-wrap,.psga-review-pages,.psga-page-paper,.psga-doc-header,.psga-signatures{max-width:100%}
.psga-table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.psga-table{min-width:1050px}
.psga-page{width:100%;box-sizing:border-box}
.psga-services{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 20px}
.psga-service-option{display:flex;align-items:flex-start;gap:7px;margin:5px 0;min-width:0}
.psga-service-check{flex:0 0 auto;font-size:14px;line-height:1.2}
.psga-annexure-selection{display:flex;flex-wrap:wrap;gap:8px 24px;margin-top:12px;font-size:10.5pt}
.psga-review-pages{width:100%;box-sizing:border-box;overflow-x:hidden}
@media (max-width:1199.98px){
    .psga-page{padding:20px}
    .psga-header{padding:18px}
    .psga-page-paper{padding:14mm 13mm 16mm}
}
@media (max-width:991.98px){
    .psga-page{padding:16px}
    .psga-header .badge{white-space:normal!important;line-height:1.35;text-align:left}
    .psga-services{grid-template-columns:1fr}
    .psga-services-two-column{grid-template-columns:1fr!important}
    .psga-signatures{grid-template-columns:1fr;gap:18px}
    .psga-doc-header{flex-wrap:wrap}
    .psga-doc-meta{margin-left:auto}
    .psga-review-pages{padding:10px}
}
@media (max-width:767.98px){
    .psga-page{padding:10px}
    .psga-header{padding:14px}
    .psga-header h3{font-size:20px}
    .psga-header .text-muted{font-size:12px}
    .psga-card{border-radius:10px}
    .psga-card > .p-3{padding:14px!important}
    .stat{padding:14px}
    .stat-value{font-size:22px}
    .psga-table{min-width:900px}
    .modal-dialog{margin:8px}
    .modal-xl{max-width:calc(100% - 16px)}
    .modal-header{padding:12px}
    .modal-footer{flex-wrap:wrap;gap:8px}
    #psgaReviewStatus{order:3;width:100%;margin:0!important}
    #psgaSendFromReviewForm{width:100%}
    #psgaSendFromReviewForm .btn{width:100%}
    .psga-review-pages .psga-page-paper{width:100%;min-height:auto;height:auto;margin:0 0 12px;padding:22px 14px 52px;font-size:10pt;overflow:visible}
    .psga-review-pages .psga-doc-logo{width:20mm;height:20mm}
    .psga-review-pages .psga-doc-agency-name{font-size:12pt}
    .psga-review-pages .psga-doc-tagline{font-size:8pt}
    .psga-review-pages .psga-doc-meta{font-size:8pt;min-width:0}
    .psga-review-pages .psga-page-paper h1{font-size:14pt}
    .psga-review-pages .psga-watermark{width:85mm}
    .psga-page-paper .footer{position:static;margin-top:18px}
}
@media (max-width:575.98px){
    .psga-page{padding:7px}
    .psga-header{padding:12px}
    .psga-header h3{font-size:18px}
    .psga-header .badge{font-size:10px;padding:7px!important}
    .psga-page-paper{font-size:9.5pt}
    .psga-review-pages{padding:5px}
    .psga-review-pages .psga-page-paper{padding:18px 11px 45px}
    .psga-review-pages .psga-doc-header{gap:8px}
    .psga-review-pages .psga-doc-logo{width:17mm;height:17mm}
    .psga-review-pages .psga-doc-agency-name{font-size:10.5pt}
    .psga-review-pages .psga-page-paper h1{font-size:12.5pt;line-height:1.25}
    .psga-table{min-width:850px}
    .psga-annexure-selection{display:block}
    .psga-annexure-selection span{display:block;margin-top:5px}
}

.service-cell{
    min-width:250px;
    width:250px;
    max-width:320px;
}
.asset-cell{
    min-width:145px;
    width:145px;
    max-width:180px;
}
.asset-cell .asset-id{
    display:block;
    white-space:nowrap;
    overflow-wrap:normal;
    word-break:normal;
}
.service-name-list{
    display:flex;
    flex-wrap:wrap;
    gap:5px;
}
.service-name-badge{
    font-weight:600;
    background:#f3f7ff;
    color:#174ea6;
    border-color:#d8e5ff;
}
.action-cell{
    min-width:190px;
    width:190px;
}
.action-wrap{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    align-items:center;
}
.action-wrap form{
    margin:0!important;
}

/* =========================================================
   RESPONSIVE PSGA TABLE
   Desktop  : normal table
   Tablet   : responsive cards from 991px and below
   Mobile   : compact responsive cards
========================================================= */
@media (max-width: 991.98px){
    .psga-table-wrap{
        overflow-x:visible;
        width:100%;
    }

    .psga-table{
        min-width:0!important;
        width:100%;
        display:block;
    }

    .psga-table thead{
        display:none;
    }

    .psga-table tbody,
    .psga-table tr,
    .psga-table td{
        display:block;
        width:100%;
        box-sizing:border-box;
    }

    .psga-table tr{
        margin:0 0 14px;
        padding:10px;
        border:1px solid #e3e7eb;
        border-radius:12px;
        background:#fff;
        box-shadow:0 2px 8px rgba(0,0,0,.04);
    }

    .psga-table td{
        padding:10px 10px 10px 35%;
        position:relative;
        min-height:40px;
        border-bottom:1px solid #f0f2f4;
        text-align:left;
        overflow-wrap:anywhere;
    }

    .psga-table td:last-child{
        border-bottom:0;
    }

    .psga-table td::before{
        content:attr(data-label);
        position:absolute;
        left:10px;
        top:10px;
        width:29%;
        font-size:11px;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.3px;
        color:#6c757d;
    }

    /* Empty-state rows */
    .psga-table td[colspan]{
        padding:22px 12px!important;
        text-align:center;
    }
    .psga-table td[colspan]::before{
        display:none;
    }

    .service-cell{
        min-width:0!important;
        width:100%!important;
        max-width:none!important;
    }

    .asset-cell{
        min-width:0!important;
        width:100%!important;
        max-width:none!important;
    }

    .asset-cell .asset-id{
        white-space:normal;
        overflow-wrap:anywhere;
        word-break:break-word;
        display:block;
        margin-bottom:3px;
    }

    .service-name-list{
        display:flex;
        flex-direction:column;
        align-items:flex-start;
        width:100%;
    }

    .service-name-badge{
        white-space:normal;
        text-align:left;
        max-width:100%;
        line-height:1.35;
    }

    .action-cell{
        min-width:0!important;
        width:100%!important;
    }

    .psga-table td[data-label="Action"]{
        padding:12px 8px 8px;
    }

    .psga-table td[data-label="Action"]::before{
        display:none;
    }

    .action-wrap{
        display:flex;
        flex-direction:column;
        align-items:stretch;
        width:100%;
        gap:7px;
    }

    .action-wrap form{
        width:100%;
    }

    .action-wrap .btn,
    .action-wrap .badge,
    .action-wrap form .btn{
        width:100%;
        text-align:center;
        white-space:normal;
    }

    /* Generate table action */
    .psga-table td[data-label="Action"] > form{
        width:100%;
    }

    .psga-table td[data-label="Action"] > form .btn{
        width:100%;
    }

    /* Owner/email should not overflow */
    .psga-table small,
    .psga-table strong{
        overflow-wrap:anywhere;
    }
}

@media (max-width:767.98px){
    .psga-page{
        padding:8px;
    }

    .psga-header{
        padding:13px;
        border-radius:10px;
    }

    .psga-header h3{
        font-size:19px;
    }

    .psga-header .text-muted{
        font-size:12px;
        line-height:1.45;
    }

    .stat{
        padding:13px;
    }

    .stat-value{
        font-size:22px;
    }

    .psga-table tr{
        padding:8px;
        margin-bottom:10px;
    }

    .psga-table td{
        padding:9px 8px 9px 38%;
        min-height:38px;
        font-size:13px;
    }

    .psga-table td::before{
        left:8px;
        top:9px;
        width:33%;
        font-size:10px;
    }

    .psga-table td[data-label="Action"]{
        padding-left:8px;
    }

    .service-name-badge{
        font-size:11px;
        padding:5px 7px;
    }

    .status{
        font-size:11px;
    }

    .modal-dialog{
        margin:6px;
    }

    .modal-xl{
        max-width:calc(100% - 12px);
    }

    .modal-footer{
        flex-wrap:wrap;
        gap:8px;
    }

    #psgaReviewStatus{
        order:3;
        width:100%;
        margin:0!important;
    }

    #psgaSendFromReviewForm{
        width:100%;
    }

    #psgaSendFromReviewForm .btn{
        width:100%;
    }

    .psga-review-pages{
        padding:5px;
    }

    .psga-review-pages .psga-page-paper{
        width:100%;
        min-height:auto;
        height:auto;
        margin:0 0 10px;
        padding:18px 11px 45px;
        font-size:9.5pt;
        overflow:visible;
    }

    .psga-review-pages .psga-doc-header{
        gap:8px;
    }

    .psga-review-pages .psga-doc-logo{
        width:17mm;
        height:17mm;
    }

    .psga-review-pages .psga-doc-agency-name{
        font-size:10.5pt;
    }

    .psga-review-pages .psga-doc-tagline{
        font-size:8pt;
    }

    .psga-review-pages .psga-doc-meta{
        font-size:8pt;
        min-width:0;
    }

    .psga-review-pages .psga-page-paper h1{
        font-size:12.5pt;
        line-height:1.25;
    }

    .psga-review-pages .psga-watermark{
        width:85mm;
    }
}

@media print{
    @page{size:A4 portrait;margin:0}

    body *{visibility:hidden!important}

    #psgaPrintArea,
    #psgaPrintArea *{
        visibility:visible!important;
    }

    #psgaPrintArea{
        position:absolute;
        left:0;
        top:0;
        width:210mm;
        margin:0;
        padding:0;
        display:block!important;
    }

    /*
     * Fixed 4-page PSGA print layout.
     * Each rendered .psga-page-paper occupies exactly one A4 sheet.
     */
    /*
     * The hidden source area contains pages for multiple PSGA records.
     * Only the four pages selected in Admin Review may be printed.
     */
    #psgaPrintArea .psga-page-paper{
        display:none!important;
        width:210mm;
        height:297mm;
        min-height:297mm;
        max-height:297mm;
        margin:0;
        padding:18mm 15mm 16mm;
        border:0;
        border-radius:0;
        box-shadow:none;
        box-sizing:border-box;
        page-break-before:auto;
        page-break-after:always;
        break-before:auto;
        break-after:page;
        overflow:hidden;
    }

    #psgaPrintArea .psga-page-paper.psga-print-target{
        display:block!important;
    }

    #psgaPrintArea .psga-page-paper:last-child{
        page-break-after:auto;
        break-after:auto;
    }

    .print-btn{
        display:none!important;
    }
}

/* =========================================================
   ADMIN PSGA — FORCE 2-COLUMN APPROVED SERVICES IN PRINT
   The print viewport must not trigger the responsive 1-column rule.
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

<div class="content">
<div class="psga-page">

<div class="psga-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h3 class="mb-1">
                <i class="bi bi-file-earmark-text me-2"></i>
                PSGA Agreements
            </h3>
            <div class="text-muted">
                Professional Service &amp; Governance Agreement — manual Admin stage after verified Advance Payment and before SAF.
            </div>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i><?=h($success)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?=h($error)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php
$total = count($psgas);
$ownerPending = 0;
$adminPending = 0;
$confirmed = 0;

foreach ($psgas as $p) {
    if (($p['Status'] ?? '') === 'Pending Owner Confirmation') {
        $ownerPending++;
    }

    if (($p['Status'] ?? '') === 'Pending Admin Confirmation') {
        $adminPending++;
    }

    if (($p['Status'] ?? '') === 'Confirmed') {
        $confirmed++;
    }
}
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="stat">
            <div class="stat-label">Total PSGA</div>
            <div class="stat-value"><?=$total?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat">
            <div class="stat-label">Owner Confirmation Pending</div>
            <div class="stat-value text-warning"><?=$ownerPending?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat">
            <div class="stat-label">Admin Confirmation Pending</div>
            <div class="stat-value text-info"><?=$adminPending?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat">
            <div class="stat-label">Confirmed / SAF Allowed</div>
            <div class="stat-value text-success"><?=$confirmed?></div>
        </div>
    </div>
</div>

<div class="psga-card mb-4">
    <div class="psga-table-wrap">
    <table class="psga-table">
        <thead>
        <tr>
            <th>Offer</th>
            <th>PSGA No.</th>
            <th>Owner</th>
            <th>Service</th>
            <th>Advance Verified</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
        </thead>

        <tbody>
        <?php if (!$eligibleOffers): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No verified Advance Payment is waiting for PSGA generation.
                </td>
            </tr>
        <?php else: foreach ($eligibleOffers as $o): ?>
            <tr>
                <td data-label="Offer">
                    <strong><?=h($o['offer_no'])?></strong>
                </td>

                <td data-label="PSGA No.">
                    <span class="psga-no"><?=h($o['psga_no'])?></span>
                </td>

                <td data-label="Owner">
                    <?=h($o['owner_name'] ?: '—')?>
                </td>

                <td data-label="Service">
                    <?=h($o['service_name'] ?: '—')?>
                </td>

                <td data-label="Advance Verified">
                    <?=!empty($o['advance_verified_at'])
                        ? h(formatISTDateTime($o['advance_verified_at']))
                        : '—'?>
                </td>

                <td data-label="Status">
                    <span class="status s-owner">
                        Ready to Generate
                    </span>
                </td>

                <td data-label="Action" class="action-cell">
                    <form method="POST"
                          onsubmit="return confirm('Generate this PSGA Agreement now? It will NOT be sent to the Owner automatically.');">
                        <?=csrfField()?>

                        <input type="hidden"
                               name="offer_id"
                               value="<?= (int)$o['offer_id'] ?>">

                        <button
                            class="btn btn-sm btn-success"
                            name="generate_psga"
                            type="submit">
                            <i class="bi bi-file-earmark-plus me-1"></i>
                            Generate PSGA Agreement
                        </button>
                    </form>

                    <div class="small text-muted mt-1">
                        Manual generation only — no automatic email.
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="psga-card">
    <div class="psga-table-wrap">
    <table class="psga-table">
        <thead>
        <tr>
            <th>PSGA No.</th>
            <th>Owner</th>
            <th>FSO</th>
            <th>Services</th>
            <th>Asset</th>
            <th>Status</th>
            <th>Contract Date</th>
            <th>Action</th>
        </tr>
        </thead>

        <tbody>
        <?php if (!$psgas): ?>
            <tr>
                <td colspan="8"
                    class="text-center text-muted py-5">
                    No PSGA Agreements generated.
                </td>
            </tr>
        <?php else: foreach ($psgas as $p): ?>

            <?php
            $sc = 's-other';

            $statusText =
                trim((string)($p['Status'] ?? ''));

            $statusKey =
                strtolower(
                    preg_replace(
                        '/\s+/',
                        ' ',
                        $statusText
                    )
                );

            if ($statusKey === 'pending owner confirmation') {
                $sc = 's-owner';
            } elseif (
                $statusKey === 'pending admin review' ||
                $statusKey === 'pending review' ||
                $statusKey === 'admin review'
            ) {
                $sc = 's-review';
            } elseif (
                $statusKey === 'pending admin confirmation'
            ) {
                $sc = 's-admin';
            } elseif ($statusKey === 'confirmed') {
                $sc = 's-confirmed';
            } elseif (
                $statusKey === 'rejected' ||
                $statusKey === 'offer rejected'
            ) {
                $sc = 's-rejected';
            }
            ?>

            <tr>
                <td data-label="PSGA No.">
                    <span class="psga-no">
                        <?=h($p['PSGA_No'])?>
                    </span>
                </td>

                <td data-label="Owner">
                    <strong>
                        <?=h($p['display_owner'] ?: '—')?>
                    </strong>

                    <?php if (!empty($p['owner_email'])): ?>
                        <br>
                        <small class="text-muted">
                            <?=h($p['owner_email'])?>
                        </small>
                    <?php endif; ?>
                </td>

                <td data-label="FSO">
                    <strong>
                        <?=h($p['fso_offer_no'] ?: '—')?>
                    </strong>

                    <?php if (!empty($p['fso_request_no'])): ?>
                        <div class="small text-muted">
                            Request:
                            <?=h($p['fso_request_no'])?>
                        </div>
                    <?php endif; ?>
                </td>

                <td data-label="Services" class="service-cell">
                    <?php
                    $serviceNames = [];
                    foreach (($p['approved_service_names'] ?? []) as $svcName) {
                        $svcName = trim((string)$svcName);
                        if ($svcName !== '') {
                            $serviceNames[] = $svcName;
                        }
                    }
                    $serviceNames = array_values(array_unique($serviceNames));
                    ?>

                    <?php if ($serviceNames): ?>
                        <div class="service-name-list">
                            <?php foreach ($serviceNames as $svcName): ?>
                                <span class="service-list-badge service-name-badge">
                                    <?=h($svcName)?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>

                <td data-label="Asset" class="asset-cell">
                    <?php if (!empty($p['asset_items'])): ?>

                        <?php foreach (
                            $p['asset_items']
                            as $assetLabel
                        ): ?>
                            <div class="asset-id"><?=h($assetLabel)?></div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>

                <td data-label="Status">
                    <span class="status <?=$sc?>">
                        <?=h(
                            trim((string)$p['Status']) !== ''
                                ? $p['Status']
                                : 'Pending Admin Review'
                        )?>
                    </span>

                    <?php
                    $adminViewedAt =
                        $p['Admin_Viewed_At'] ?? '';

                    $adminViewedSession =
                        !empty(
                            $_SESSION['psga_admin_viewed']
                                [$p['PSGA_ID']]
                        );
                    ?>

                    <?php if (
                        $p['Status'] === 'Pending Admin Review' &&
                        ($adminViewedAt || $adminViewedSession)
                    ): ?>

                        <div class="small text-muted mt-1">
                            Viewed by Admin
                            <?=
                                $adminViewedAt
                                    ? ': ' .
                                      h(
                                          formatISTDateTime(
                                              $adminViewedAt
                                          )
                                      )
                                    : ''
                            ?>
                        </div>

                    <?php endif; ?>
                </td>

                <td data-label="Contract Date">
                    <?=
                        !empty($p['Contract_Date'])
                            ? h(
                                formatISTDateOnly(
                                    $p['Contract_Date']
                                )
                            )
                            : '—'
                    ?>
                </td>

                <td data-label="Action" class="action-cell">
                    <div class="action-wrap">
                    <button
                        class="btn btn-sm btn-outline-primary"
                        type="button"
                        onclick="viewPsgaAgreement(<?= (int)$p['PSGA_ID'] ?>)"
                        title="Open PSGA agreement for Admin review">
                        <i class="bi bi-eye me-1"></i>
                        View PSGA
                    </button>

                    <?php if (!empty($p['fso_offer_id'])): ?>
                    <form
                        method="POST"
                        class="d-inline-block"
                        onsubmit="return confirmRegeneratePsga('<?=h($p['PSGA_No'])?>', '<?=h($p['fso_offer_no'] ?? '')?>');">

                        <?=csrfField()?>

                        <input
                            type="hidden"
                            name="psga_id"
                            value="<?= (int)$p['PSGA_ID'] ?>">

                        <button
                            class="btn btn-sm btn-outline-warning"
                            name="regenerate_psga"
                            type="submit"
                            title="Regenerate the PSGA agreement using the latest FSO, services and assets">
                            <i class="bi bi-arrow-repeat me-1"></i>
                            Regenerate PSGA
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if (
                        $p['Status'] === 'Pending Admin Review'
                    ): ?>

                        <?php if (
                            !empty($p['Admin_Viewed_At']) ||
                            !empty(
                                $_SESSION['psga_admin_viewed']
                                    [$p['PSGA_ID']]
                            )
                        ): ?>

                            <form
                                method="POST"
                                class="d-inline"
                                onsubmit="return confirm('Send this PSGA to the Owner now? The Owner will receive the PSGA email and it will move to Pending Owner Confirmation.');">

                                <?=csrfField()?>

                                <input
                                    type="hidden"
                                    name="psga_id"
                                    value="<?= (int)$p['PSGA_ID'] ?>">

                                <button
                                    class="btn btn-sm btn-success"
                                    name="send_psga_owner"
                                    type="submit">
                                    <i class="bi bi-send me-1"></i>
                                    Send to Owner
                                </button>
                            </form>

                        <?php else: ?>

                            <span class="badge bg-secondary">
                                View first
                            </span>

                        <?php endif; ?>

                    <?php elseif (
                        $p['Status'] === 'Pending Admin Confirmation'
                    ): ?>

                        <form
                            method="POST"
                            class="d-inline"
                            onsubmit="return confirm('Confirm this PSGA? SAF will become available after confirmation.');">

                            <?=csrfField()?>

                            <input
                                type="hidden"
                                name="psga_id"
                                value="<?= (int)$p['PSGA_ID'] ?>">

                            <button
                                class="btn btn-sm btn-success"
                                name="confirm_psga"
                                type="submit">
                                <i class="bi bi-check2-circle me-1"></i>
                                Confirm PSGA
                            </button>
                        </form>

                    <?php elseif (
                        $p['Status'] === 'Confirmed'
                    ): ?>

                        <span class="badge bg-success">
                            SAF Allowed
                        </span>

                    <?php endif; ?>

                    <?php if (!empty($p['fso_offer_id'])): ?>

                        <form
                            method="POST"
                            class="d-inline-block"
                            onsubmit="return confirmDeleteFso('<?=h($p['fso_offer_no'])?>');">

                            <?=csrfField()?>

                            <input
                                type="hidden"
                                name="offer_id"
                                value="<?= (int)$p['fso_offer_id'] ?>">

                            <button
                                class="btn btn-sm btn-outline-danger"
                                name="delete_fso"
                                type="submit"
                                title="Delete only this FSO">
                                <i class="bi bi-trash3 me-1"></i>
                                Delete PSGA
                            </button>
                        </form>

                    <?php else: ?>

                        <form
                            method="POST"
                            class="d-inline-block"
                            onsubmit="return confirmDeletePsga('<?=h($p['PSGA_No'])?>');">

                            <?=csrfField()?>

                            <input
                                type="hidden"
                                name="psga_id"
                                value="<?= (int)$p['PSGA_ID'] ?>">

                            <button
                                class="btn btn-sm btn-outline-danger"
                                name="delete_psga"
                                type="submit"
                                title="Delete PSGA because no FSO is linked">
                                <i class="bi bi-trash3 me-1"></i>
                                Delete PSGA
                            </button>
                        </form>

                    <?php endif; ?>
                    </div>
                </td>
            </tr>

        <?php endforeach; endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- =====================================================
     PSGA ADMIN REVIEW MODAL
====================================================== -->
<div
    class="modal fade"
    id="psgaReviewModal"
    tabindex="-1"
    aria-hidden="true">

    <div class="modal-dialog modal-xl modal-dialog-scrollable">

        <div class="modal-content">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title mb-1">
                        <i class="bi bi-file-earmark-text me-2"></i>
                        PSGA Agreement Review
                    </h5>

                    <small class="text-muted">
                        Review the complete 4-page A4 agreement before sending it to Owner.
                    </small>
                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal">
                </button>
            </div>

            <div class="modal-body p-0">
                <div
                    id="psgaReviewPages"
                    class="psga-review-pages">
                </div>
            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-outline-secondary"
                    onclick="printReviewedPsga()">
                    <i class="bi bi-printer me-1"></i>
                    Print
                </button>

                <span
                    id="psgaReviewStatus"
                    class="me-auto text-muted small">
                </span>

                <form
                    method="POST"
                    id="psgaSendFromReviewForm"
                    onsubmit="return confirm('Send this PSGA Agreement to the Owner now? The Owner will receive the PSGA email.');">

                    <?=csrfField()?>

                    <input
                        type="hidden"
                        name="psga_id"
                        id="psgaReviewPsgaId"
                        value="">

                    <button
                        type="submit"
                        class="btn btn-success"
                        name="send_psga_owner"
                        id="psgaSendOwnerBtn">
                        <i class="bi bi-send me-1"></i>
                        Send to Owner
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
/*
 * Shared PSGA renderer.
 * The renderer is responsible for the fixed 4-page A4 document:
 *   Page 1 = Points 1-2
 *   Page 2 = Points 3-6
 *   Page 3 = Points 7-10
 *   Page 4 = Points 11-14 + Signatures
 */
require_once('saf-templates/psga-generator.php');
?>

<div
    id="psgaPrintArea"
    class="psga-paper-wrap"
    style="display:none">

<?php foreach ($psgas as $p): ?>
    <?php renderNletaPsga($p, false); ?>
<?php endforeach; ?>

</div>

<script>
function confirmRegeneratePsga(psgaNo, fsoNo){
    const fsoText = fsoNo ? fsoNo : 'latest mapped FSO';

    return confirm(
        'REGENERATE PSGA AGREEMENT\\n\\n' +
        'PSGA: ' + psgaNo + '\\n' +
        'FSO: ' + fsoText + '\\n\\n' +
        'The same permanent PSGA number will be retained.\\n' +
        'The agreement will be re-synced using the latest FSO services and assets.\\n' +
        'No new PSGA record will be created.\\n' +
        'Existing PSGA status will be preserved.\\n\\n' +
        'Do you want to regenerate this PSGA agreement?'
    );
}

function confirmDeletePsga(psgaNo){
    return confirm(
        'DELETE PSGA\n\n' +
        'PSGA: ' + psgaNo + '\n\n' +
        'No FSO is currently linked to this PSGA.\n' +
        'This will permanently delete the PSGA Agreement record.\n' +
        'Owner and Asset master data will NOT be deleted.\n\n' +
        'Are you sure you want to delete this PSGA?'
    );
}

function confirmDeleteFso(fsoNo){
    return confirm(
        'DELETE PSGA\n\n' +
        'FSO: ' + fsoNo + '\n\n' +
        'Only this FSO / Offer and its payment records will be deleted.\n' +
        'The permanent PSGA will NOT be deleted.\n' +
        'Owner, Service Request and Assets will NOT be deleted.\n\n' +
        'WARNING: Payment history for this FSO will be permanently removed.\n\n' +
        'Are you sure you want to delete this FSO?'
    );
}

let currentReviewPsgaId = 0;
let currentReviewDocumentNodes = [];

function viewPsgaAgreement(id){
    currentReviewPsgaId = id;

    const sourceArea =
        document.getElementById('psgaPrintArea');

    const reviewPages =
        document.getElementById('psgaReviewPages');

    const first =
        document.getElementById('psgaDoc' + id);

    const sendId =
        document.getElementById('psgaReviewPsgaId');

    const reviewStatus =
        document.getElementById('psgaReviewStatus');

    if (
        !sourceArea ||
        !reviewPages ||
        !first
    ) {
        alert(
            'PSGA agreement preview could not be loaded.'
        );
        return;
    }

    reviewPages.innerHTML = '';
    currentReviewDocumentNodes = [];

    const all =
        Array.from(
            sourceArea.querySelectorAll(
                '.psga-page-paper'
            )
        );

    const index = all.indexOf(first);

    /*
     * PSGA document is fixed to 4 A4 pages:
     * Page 1 = Points 1-2
     * Page 2 = Points 3-6
     * Page 3 = Points 7-10
     * Page 4 = Points 11-14 + Signatures
     *
     * Load all four pages in Admin Review so the same complete
     * document is available for review and printing.
     */
    for (
        let i = index;
        i < index + 4;
        i++
    ) {
        if (!all[i]) continue;

        const clone =
            all[i].cloneNode(true);

        clone.style.display = 'block';
        clone.removeAttribute('id');

        reviewPages.appendChild(clone);

        currentReviewDocumentNodes.push(
            clone
        );
    }

    if (sendId) {
        sendId.value = id;
    }

    if (reviewStatus) {
        reviewStatus.innerHTML =
            '<i class="bi bi-check-circle-fill text-success me-1"></i>' +
            'Complete 4-page PSGA Agreement opened for Admin review. Review all pages and then click <strong>Send to Owner</strong>.';
    }

    const fd = new FormData();

    const csrf =
        document.querySelector(
            'input[name="csrf_token"]'
        );

    if (csrf) {
        fd.append(
            'csrf_token',
            csrf.value
        );
    }

    fd.append(
        'psga_id',
        String(id)
    );

    fd.append(
        'view_psga',
        '1'
    );

    fetch(
        window.location.href,
        {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        }
    ).catch(function(){});

    const modalElement =
        document.getElementById(
            'psgaReviewModal'
        );

    if (
        window.bootstrap &&
        bootstrap.Modal
    ) {
        bootstrap.Modal
            .getOrCreateInstance(
                modalElement
            )
            .show();
    } else {
        modalElement.style.display = 'block';
        modalElement.classList.add('show');
    }
}

function printReviewedPsga(){
    if (
        !currentReviewDocumentNodes.length
    ) {
        return;
    }

    /*
     * A valid PSGA review should contain four A4 pages.
     * If the shared renderer returns fewer pages for an old/invalid
     * document, print only what actually exists rather than creating
     * blank/invented content.
     */
    if (currentReviewDocumentNodes.length > 4) {
        currentReviewDocumentNodes =
            currentReviewDocumentNodes.slice(0, 4);
    }

    const printArea =
        document.getElementById(
            'psgaPrintArea'
        );

    if (!printArea) {
        return;
    }

    const oldDisplay =
        printArea.style.display;

    const oldNodes =
        Array.from(
            printArea.querySelectorAll(
                '.psga-page-paper'
            )
        );

    printArea.style.display =
        'block';

    oldNodes.forEach(
        function(node){
            node.style.display =
                'none';
        }
    );

    currentReviewDocumentNodes.forEach(
        function(node){
            node.classList.add(
                'psga-print-target'
            );

            printArea.appendChild(
                node
            );
        }
    );

    window.print();

    setTimeout(
        function(){

            currentReviewDocumentNodes.forEach(
                function(node){

                    node.classList.remove(
                        'psga-print-target'
                    );

                    const reviewPages =
                        document.getElementById(
                            'psgaReviewPages'
                        );

                    if (reviewPages) {
                        reviewPages.appendChild(
                            node
                        );
                    }
                }
            );

            oldNodes.forEach(
                function(node){
                    node.style.display =
                        'block';
                }
            );

            printArea.style.display =
                oldDisplay;

        },
        800
    );
}
</script>

<?php include('../includes/footer.php'); ?>
