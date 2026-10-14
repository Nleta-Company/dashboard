<?php

/**
 * Display database datetime in IST.
 * Stored DB value is not modified.
 */
if (!function_exists('formatISTDateTime')) {
    function formatISTDateTime($value, string $format = 'd-m-Y h:i A'): string
    {
        $value = trim((string)$value);

        if (
            $value === '' ||
            $value === '0000-00-00' ||
            $value === '0000-00-00 00:00:00'
        ) {
            return '';
        }

        try {
            // Database timestamps are treated as UTC for display conversion.
            $dt = new DateTime($value, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

            return $dt->format($format) . ' IST';
        } catch (Throwable $e) {
            return $value;
        }
    }
}

/* =========================================================
   NLETA ADMIN — SAF CONTRACTS
   ---------------------------------------------------------
   FINAL PSGA → SAF WORKFLOW

   Permanent PSGA:
       Advance Verified
            ↓
       PSGA Generated
            ↓
       Owner Confirmation
            ↓
       Admin Confirmation
            ↓
       SAF Contract page
            ↓
       SAF Generation / Review
            ↓
       Proposed Inspection Date
            ↓
       Send to Owner
            ↓
       Owner OTP Acceptance
            ↓
       Accepted
            ↓
       Inspector Assignment

   IMPORTANT:
   - PSGA is permanent and is NEVER deleted here.
   - SAF is generated only after PSGA is Confirmed.
   - SAF generation does NOT change PSGA.
   - Existing SAF review / forwarding / OTP / checklist /
     inspector workflow remains based on saf_contracts.
   - SAF generation is available for PAT, SAT, SCI and DGS.
   - SAF numbering is independent for each Owner and starts at 0001 for a new Owner.
   - MULTI-SERVICE + MULTI-ASSET:
       PAT / SAT / SCI → one SAF per selected asset + service.
       DGS → one governance SAF per FSO / PSGA.
   - Each SAF belongs to exactly ONE service.
   - Same asset may therefore have separate PAT, SAT and SCI SAFs.
   - DGS is NOT duplicated per asset and does NOT require Inspector work.
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');
require_once('../config/dgs_access.php');
require_once('../config/nleta-mailer.php');

require_role('admin');

date_default_timezone_set('Asia/Kolkata');

$error = '';
$success = '';

$adminId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);

/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) return false;

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*) AS c
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    if (!$stmt) return false;

    $stmt->bind_param('s', $table);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int)($row['c'] ?? 0) > 0;
}

function safDateTime($value): string
{
    if (!$value) return '—';

    try {
        $dt = new DateTime((string)$value, new DateTimeZone('Asia/Kolkata'));
        return $dt->format('d-m-Y h:i A') . ' IST';
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function safDateOnly($value): string
{
    if (!$value) return '—';

    try {
        return (new DateTime((string)$value))->format('d-m-Y');
    } catch (Throwable $e) {
        return (string)$value;
    }
}

function maintenanceJobTypeSupportsSafInspection(mysqli $conn): bool
{
    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'maintenance_jobs'
          AND COLUMN_NAME = 'job_type'
        LIMIT 1
    ");

    if (!$stmt) return true;

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) return true;

    $type = strtolower((string)$row['COLUMN_TYPE']);

    if (strpos($type, 'enum(') !== 0) {
        return true;
    }

    return strpos($type, "'saf_inspection'") !== false;
}

function ensureSafInspectionJobType(mysqli $conn): bool
{
    if (maintenanceJobTypeSupportsSafInspection($conn)) {
        return true;
    }

    $stmt = $conn->prepare("
        SELECT COLUMN_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'maintenance_jobs'
          AND COLUMN_NAME = 'job_type'
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Unable to inspect maintenance_jobs.job_type.');
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new Exception('maintenance_jobs.job_type column was not found.');
    }

    $columnType = (string)$row['COLUMN_TYPE'];

    if (stripos($columnType, 'enum(') !== 0) {
        return true;
    }

    $values = [];
    if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $columnType, $m)) {
        foreach ($m[1] as $v) {
            $values[] = "'" . str_replace("'", "''", $v) . "'";
        }
    }

    if (!in_array("'saf_inspection'", $values, true)) {
        $values[] = "'saf_inspection'";
    }

    $newType = 'ENUM(' . implode(',', $values) . ')';

    $sql = "ALTER TABLE maintenance_jobs MODIFY COLUMN job_type {$newType}";

    if (!$conn->query($sql)) {
        throw new Exception(
            'Unable to enable SAF inspection job type: ' . $conn->error
        );
    }

    return true;
}


function safGetSelectedServices(mysqli $conn, int $requestId, int $fallbackServiceId = 0): array
{
    $services = [];

    /*
     * Phase-4 multi-service is authoritative:
     * service_request_services(request_id, service_id)
     * → service_catalog(id, service_code, service_name)
     */
    if (
        $requestId > 0 &&
        tableExists($conn, 'service_request_services') &&
        tableExists($conn, 'service_catalog')
    ) {
        $stmt = $conn->prepare("
            SELECT DISTINCT
                sc.id AS service_id,
                TRIM(COALESCE(sc.service_code, '')) AS service_code,
                TRIM(COALESCE(sc.service_name, '')) AS service_name
            FROM service_request_services srs
            INNER JOIN service_catalog sc
                ON sc.id = srs.service_id
            WHERE srs.request_id = ?
            ORDER BY srs.id ASC
        ");

        if ($stmt) {
            $stmt->bind_param('i', $requestId);
            $stmt->execute();

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $sid = (int)($row['service_id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }

                $code = strtoupper(trim((string)($row['service_code'] ?? '')));
                $name = trim((string)($row['service_name'] ?? ''));

                if ($name === '') {
                    $name = $code !== '' ? $code : 'Service not configured';
                }

                $services[$sid] = [
                    'service_id'   => $sid,
                    'service_code' => $code,
                    'service_name' => $name
                ];
            }

            $stmt->close();
        }
    }

    /*
     * Legacy fallback:
     * old requests have only service_requests.service_id.
     */
    if (!$services && $fallbackServiceId > 0 && tableExists($conn, 'service_catalog')) {
        $stmt = $conn->prepare("
            SELECT
                id AS service_id,
                TRIM(COALESCE(service_code, '')) AS service_code,
                TRIM(COALESCE(service_name, '')) AS service_name
            FROM service_catalog
            WHERE id = ?
            LIMIT 1
        ");

        if ($stmt) {
            $stmt->bind_param('i', $fallbackServiceId);
            $stmt->execute();

            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($row) {
                $code = strtoupper(trim((string)($row['service_code'] ?? '')));
                $name = trim((string)($row['service_name'] ?? ''));

                if ($name === '') {
                    $name = $code !== '' ? $code : 'Service not configured';
                }

                $services[$fallbackServiceId] = [
                    'service_id'   => $fallbackServiceId,
                    'service_code' => $code,
                    'service_name' => $name
                ];
            }
        }
    }

    return array_values($services);
}

function safEligibleServices(array $services): array
{
    $eligible = [];

    foreach ($services as $service) {
        $code = strtoupper(trim((string)($service['service_code'] ?? '')));

        /*
         * DGS is the canonical SAF code.
         * Older service_catalog rows may still use DG for
         * "Digital Governance Support", so accept both.
         */
        if (in_array($code, ['PAT', 'SAT', 'SCI', 'DGS', 'DG'], true)) {
            $eligible[] = $service;
        }
    }

    return $eligible;
}

function safIsDgsServiceCode($code): bool
{
    return in_array(
        strtoupper(trim((string)$code)),
        ['DGS', 'DG'],
        true
    );
}

function safServiceLabels(array $services): array
{
    $labels = [];
    $seen = [];

    foreach ($services as $service) {
        $code = strtoupper(trim((string)($service['service_code'] ?? '')));
        $name = trim((string)($service['service_name'] ?? ''));

        if ($name === '' && $code === '') {
            continue;
        }

        $label = $code !== '' && $name !== ''
            ? $code . ' - ' . $name
            : ($name !== '' ? $name : $code);

        $key = strtolower($label);
        if (isset($seen[$key])) {
            continue;
        }

        $seen[$key] = true;
        $labels[] = $label;
    }

    return $labels;
}

function safServiceLabelsHtml(array $services): string
{
    $labels = safServiceLabels($services);

    if (!$labels) {
        return '<span class="text-muted">Service not configured</span>';
    }

    $html = '';
    foreach ($labels as $label) {
        $html .= '<div class="service-name-line">' . h($label) . '</div>';
    }

    return $html;
}

/* =========================================================
   GENERATE SAF CONTRACTS — MULTI-SERVICE + MULTI-ASSET
   ---------------------------------------------------------
   Phase-4 may contain MULTIPLE selected services.

   Example:
       PAT + SAT + DG

   SAF document generation is allowed only for:
       PAT / SAT / SCI

   Therefore:
       - PAT → SAF generated
       - SAT → SAF generated
       - SCI → SAF generated
       - DG  → no SAF (not an SAF document type)

   For every SAF-eligible selected service:
       ONE SAF CONTRACT is created for EACH selected asset.

   Example:
       5 assets × (PAT + SAT)
       = 10 SAF contracts

   Existing SAFs are skipped, so clicking Generate SAF again
   only creates missing asset/service combinations.
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_saf'])) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $offerId = (int)($_POST['offer_id'] ?? 0);

        if ($offerId <= 0) {
            $error = 'Invalid FSO selected.';
        } else {
            try {
                $offerStmt = $conn->prepare("
                    SELECT
                        o.id AS offer_id,
                        o.offer_no,
                        o.psga_no,
                        o.request_id,
                        sr.request_no,
                        sr.owner_id,
                        sr.service_id,
                        c.PSGA_ID,
                        c.Status AS psga_status,
                        c.Owner_ID AS psga_owner_id
                    FROM nleta_offer_master o
                    INNER JOIN service_requests sr
                        ON sr.id = o.request_id
                    INNER JOIN contracts c
                        ON c.PSGA_No = o.psga_no
                    WHERE o.id = ?
                    LIMIT 1
                ");

                if (!$offerStmt) {
                    throw new Exception(
                        'Unable to load FSO: ' . $conn->error
                    );
                }

                $offerStmt->bind_param('i', $offerId);
                $offerStmt->execute();

                $offer = $offerStmt->get_result()->fetch_assoc();
                $offerStmt->close();

                if (!$offer) {
                    throw new Exception('FSO not found.');
                }

                if (trim((string)$offer['psga_status']) !== 'Confirmed') {
                    throw new Exception(
                        'SAF can be generated only after Admin has Confirmed the PSGA.'
                    );
                }

                $requestId = (int)$offer['request_id'];

                /*
                 * Load ALL assets selected in Phase-4.
                 */
                $assetStmt = $conn->prepare("
                    SELECT DISTINCT
                        sra.asset_id,
                        a.display_asset_id,
                        a.asset_name,
                        a.location
                    FROM service_request_assets sra
                    INNER JOIN assets a
                        ON a.id = sra.asset_id
                    WHERE sra.request_id = ?
                    ORDER BY a.id ASC
                ");

                if (!$assetStmt) {
                    throw new Exception(
                        'Unable to load selected assets: ' . $conn->error
                    );
                }

                $assetStmt->bind_param('i', $requestId);
                $assetStmt->execute();

                $assetResult = $assetStmt->get_result();
                $selectedAssets = [];

                while ($asset = $assetResult->fetch_assoc()) {
                    $aid = (int)$asset['asset_id'];

                    if ($aid > 0) {
                        $selectedAssets[$aid] = $asset;
                    }
                }

                $assetStmt->close();

                if (!$selectedAssets) {
                    throw new Exception(
                        'No assets are linked to FSO ' .
                        ($offer['offer_no'] ?: ('#' . $offerId)) .
                        '.'
                    );
                }

                /*
                 * Load ALL services selected by the Owner in Phase-4.
                 * Never fall back to only one service when the
                 * multi-service relation exists.
                 */
                $allSelectedServices = safGetSelectedServices(
                    $conn,
                    $requestId,
                    (int)($offer['service_id'] ?? 0)
                );

                if (!$allSelectedServices) {
                    throw new Exception(
                        'No selected service was found for this FSO.'
                    );
                }

                /*
                 * PAT / SAT / SCI / DGS create SAF contracts.
                 * Other selected services remain part of the FSO/PSGA,
                 * but do not create an SAF document.
                 */
                $eligibleServices = safEligibleServices($allSelectedServices);

                if (!$eligibleServices) {
                    throw new Exception(
                        'The selected services do not contain PAT, SAT, SCI or DGS. ' .
                        'SAF generation is available for PAT, SAT, SCI and DGS.'
                    );
                }

                $conn->begin_transaction();

                try {
                    /*
                     * =====================================================
                     * OWNER-WISE SAF NUMBERING
                     * -----------------------------------------------------
                     * Each Owner has an independent SAF sequence.
                     *
                     * Owner A:
                     *     0001, 0002, 0003...
                     *
                     * New Owner B:
                     *     0001, 0002, 0003...
                     *
                     * PSGA is permanent and independent from SAF numbering.
                     * SAF number is NOT based on PSGA or FSO number.
                     *
                     * Existing SAF numbers are never changed.
                     * Only future SAF generation uses this Owner-wise rule.
                     * =====================================================
                     */
                    $ownerIdForSaf = (int)(
                        $offer['owner_id']
                        ?: $offer['psga_owner_id']
                    );

                    if ($ownerIdForSaf <= 0) {
                        throw new Exception(
                            'Unable to determine Owner for SAF numbering.'
                        );
                    }

                    /*
                     * Lock the Owner's SAF rows and find the highest
                     * SAF number belonging ONLY to this Owner.
                     *
                     * A new Owner therefore starts at 0001.
                     */
                    $nextResult = $conn->prepare("
                        SELECT
                            COALESCE(
                                MAX(CAST(saf_no AS UNSIGNED)),
                                0
                            ) AS max_saf_no
                        FROM saf_contracts
                        WHERE owner_id = ?
                          AND saf_no IS NOT NULL
                          AND TRIM(saf_no) <> ''
                          AND saf_no REGEXP '^[0-9]+$'
                        FOR UPDATE
                    ");

                    if (!$nextResult) {
                        throw new Exception(
                            'Unable to determine next Owner-wise SAF number: ' .
                            $conn->error
                        );
                    }

                    $nextResult->bind_param(
                        'i',
                        $ownerIdForSaf
                    );

                    if (!$nextResult->execute()) {
                        $nextError = $nextResult->error;
                        $nextResult->close();

                        throw new Exception(
                            'Unable to determine next Owner-wise SAF number: ' .
                            $nextError
                        );
                    }

                    $nextRow =
                        $nextResult
                            ->get_result()
                            ->fetch_assoc();

                    $nextResult->close();

                    $nextSafNo =
                        (int)($nextRow['max_saf_no'] ?? 0);

                    $insert = $conn->prepare("
                        INSERT INTO saf_contracts
                        (
                            offer_id,
                            request_id,
                            owner_id,
                            asset_id,
                            psga_no,
                            saf_no,
                            service_id,
                            service_code,
                            service_name,
                            saf_type,
                            activation_date,
                            proposed_inspection_date,
                            checklist_reference,
                            service_output,
                            applicable_fee,
                            status,
                            generated_at,
                            created_at,
                            updated_at
                        )
                        VALUES
                        (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            NULLIF(?,0),
                            ?,
                            ?,
                            ?,
                            CURDATE(),
                            NULL,
                            '',
                            '',
                            0,
                            'Pending Admin Review',
                            NOW(),
                            NOW(),
                            NOW()
                        )
                    ");

                    if (!$insert) {
                        throw new Exception(
                            'Unable to prepare SAF creation: ' .
                            $conn->error
                        );
                    }

                    $createdCount = 0;
                    $skippedCount = 0;

                    /*
                     * SAF creation rules:
                     *
                     * PAT / SAT / SCI:
                     *     one SAF per OFFER + ASSET + SERVICE.
                     *
                     * DGS:
                     *     ONE SAF per OFFER + SERVICE.
                     *     DGS is governance activation, not an inspection.
                     *
                     * For DGS, asset_id is anchored to the first selected
                     * asset only when the database column requires a value.
                     * The DGS SAF itself is displayed as asset-independent.
                     */
                    foreach ($eligibleServices as $service) {

                        $serviceId = (int)($service['service_id'] ?? 0);
                        $rawServiceCode = strtoupper(
                            trim((string)($service['service_code'] ?? ''))
                        );
                        $serviceCode = safIsDgsServiceCode($rawServiceCode)
                            ? 'DGS'
                            : $rawServiceCode;

                        $serviceName = trim(
                            (string)($service['service_name'] ?? '')
                        );

                        if ($serviceName === '') {
                            $serviceName = $serviceCode !== ''
                                ? $serviceCode
                                : 'Service not configured';
                        }

                        /*
                         * DGS / legacy DG:
                         * one SAF for the whole FSO/PSGA.
                         */
                        if ($serviceCode === 'DGS') {

                            $dgsAnchorAssetId = (int)array_key_first(
                                $selectedAssets
                            );

                            /*
                             * Existing DGS SAF is checked by OFFER +
                             * SERVICE. The asset is deliberately not part
                             * of the DGS uniqueness decision.
                             */
                            $existingStmt = $conn->prepare("
                                SELECT id, saf_no
                                FROM saf_contracts
                                WHERE offer_id = ?
                                  AND (
                                        service_id = ?
                                        OR (
                                            service_id IS NULL
                                            AND UPPER(TRIM(service_code)) IN ('DGS','DG')
                                        )
                                  )
                                ORDER BY id DESC
                                LIMIT 1
                            ");

                            if (!$existingStmt) {
                                throw new Exception(
                                    'Unable to check existing DGS SAF: ' .
                                    $conn->error
                                );
                            }

                            $existingStmt->bind_param(
                                'ii',
                                $offerId,
                                $serviceId
                            );

                            $existingStmt->execute();

                            $existingSaf =
                                $existingStmt->get_result()->fetch_assoc();

                            $existingStmt->close();

                            if ($existingSaf) {
                                $skippedCount++;
                                continue;
                            }

                            $nextSafNo++;

                            $safNo = str_pad(
                                (string)$nextSafNo,
                                4,
                                '0',
                                STR_PAD_LEFT
                            );

                            $ownerId = $ownerIdForSaf;

                            $safType = 'DGS';

                            $insert->bind_param(
                                'iiiississs',
                                $offerId,
                                $requestId,
                                $ownerId,
                                $dgsAnchorAssetId,
                                $offer['psga_no'],
                                $safNo,
                                $serviceId,
                                $serviceCode,
                                $serviceName,
                                $safType
                            );

                            if (!$insert->execute()) {
                                throw new Exception(
                                    'Unable to create DGS SAF: ' .
                                    $insert->error
                                );
                            }

                            $createdCount++;
                            continue;
                        }

                        /*
                         * PAT / SAT / SCI:
                         * one SAF for every selected asset.
                         */
                        foreach ($selectedAssets as $assetId => $asset) {

                            $existingStmt = $conn->prepare("
                                SELECT id, saf_no
                                FROM saf_contracts
                                WHERE offer_id = ?
                                  AND asset_id = ?
                                  AND (
                                        service_id = ?
                                        OR (
                                            service_id IS NULL
                                            AND UPPER(TRIM(service_code)) = ?
                                        )
                                  )
                                ORDER BY id DESC
                                LIMIT 1
                            ");

                            if (!$existingStmt) {
                                throw new Exception(
                                    'Unable to check existing SAF: ' .
                                    $conn->error
                                );
                            }

                            $existingStmt->bind_param(
                                'iiis',
                                $offerId,
                                $assetId,
                                $serviceId,
                                $serviceCode
                            );

                            $existingStmt->execute();

                            $existingSaf =
                                $existingStmt->get_result()->fetch_assoc();

                            $existingStmt->close();

                            if ($existingSaf) {
                                $skippedCount++;
                                continue;
                            }

                            $nextSafNo++;

                            $safNo = str_pad(
                                (string)$nextSafNo,
                                4,
                                '0',
                                STR_PAD_LEFT
                            );

                            $ownerId = (int)(
                                $offer['owner_id']
                                ?: $offer['psga_owner_id']
                            );

                            $safType = $serviceCode !== ''
                                ? $serviceCode
                                : strtoupper(
                                    preg_replace(
                                        '/[^A-Za-z0-9]+/',
                                        '_',
                                        $serviceName
                                    )
                                );

                            if ($safType === '') {
                                $safType = 'SERVICE';
                            }

                            $insert->bind_param(
                                'iiiississs',
                                $offerId,
                                $requestId,
                                $ownerId,
                                $assetId,
                                $offer['psga_no'],
                                $safNo,
                                $serviceId,
                                $serviceCode,
                                $serviceName,
                                $safType
                            );

                            if (!$insert->execute()) {
                                throw new Exception(
                                    'Unable to create SAF for ' .
                                    ($asset['display_asset_id']
                                        ?: ('Asset #' . $assetId)) .
                                    ' / ' .
                                    ($serviceName ?: $serviceCode) .
                                    ': ' .
                                    $insert->error
                                );
                            }

                            $createdCount++;
                        }
                    }

                    $insert->close();

                    $conn->commit();

                    $serviceLabels = [];

                    foreach ($eligibleServices as $service) {
                        $label = trim(
                            (string)($service['service_name'] ?? '')
                        );

                        if ($label === '') {
                            $label = trim(
                                (string)($service['service_code'] ?? '')
                            );
                        }

                        if ($label !== '') {
                            $serviceLabels[] = $label;
                        }
                    }

                    $success =
                        $createdCount .
                        ' SAF Contract' .
                        ($createdCount === 1 ? '' : 's') .
                        ' generated for ' .
                        ' — Services: ' .
                        implode(', ', $serviceLabels) .
                        '.';

                    if ($skippedCount > 0) {
                        $success .=
                            ' ' .
                            $skippedCount .
                            ' existing asset/service combination' .
                            ($skippedCount === 1 ? '' : 's') .
                            ' already had SAF and were skipped.';
                    }

                    /*
                     * Inform Admin if the FSO contains selected services
                     * that are not SAF document types.
                     */
                    $nonSafServices = [];

                    foreach ($allSelectedServices as $service) {
                        $code = strtoupper(
                            trim((string)($service['service_code'] ?? ''))
                        );

                        if (!in_array($code, ['PAT', 'SAT', 'SCI', 'DGS', 'DG'], true)) {
                            $label = trim(
                                (string)($service['service_name'] ?? '')
                            );

                            if ($label === '') {
                                $label = $code !== ''
                                    ? $code
                                    : 'Service';
                            }

                            $nonSafServices[] = $label;
                        }
                    }

                    if ($nonSafServices) {
                        $success .=
                            ' SAF was not created for: ' .
                            implode(', ', array_unique($nonSafServices)) .
                            ' because those services do not require an SAF document.';
                    }

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
   FORWARD DGS SAF TO OWNER
   ---------------------------------------------------------
   DGS is a governance service, not an inspection service.
   Therefore it does NOT require a Proposed Inspection Date.
   Its activation date is the SAF activation date.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['forward_dgs_saf'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $safId = (int)($_POST['saf_id'] ?? 0);

        if ($safId <= 0) {
            $error = 'Invalid DGS SAF selected.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT id, saf_no, status, service_code
                    FROM saf_contracts
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to check DGS SAF: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $safId);
                $stmt->execute();

                $dgsSaf = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$dgsSaf) {
                    throw new Exception('DGS SAF not found.');
                }

                if (!safIsDgsServiceCode($dgsSaf['service_code'])) {
                    throw new Exception('This SAF is not a Digital Governance Support SAF.');
                }

                if ($dgsSaf['status'] !== 'Pending Admin Review') {
                    throw new Exception(
                        'This DGS SAF is not pending Admin review.'
                    );
                }

                $update = $conn->prepare("
                    UPDATE saf_contracts
                    SET
                        status = 'Pending Owner Acceptance',
                        admin_reviewed_at = NOW(),
                        forwarded_to_owner_at = NOW(),
                        updated_at = NOW()
                    WHERE
                        id = ?
                        AND status = 'Pending Admin Review'
                ");

                if (!$update) {
                    throw new Exception(
                        'Unable to prepare DGS SAF forwarding: ' . $conn->error
                    );
                }

                $update->bind_param('i', $safId);

                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new Exception('DGS SAF could not be forwarded.');
                }

                $update->close();

                $success =
                    'DGS SAF ' . $dgsSaf['saf_no'] .
                    ' forwarded to Owner successfully.';

            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   FORWARD SAF TO OWNER
========================================================= */


if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['forward_saf'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $safId = (int)($_POST['saf_id'] ?? 0);
        $proposedDate = trim((string)($_POST['proposed_inspection_date'] ?? ''));

        if ($safId <= 0) {
            $error = 'Invalid SAF selected.';
        } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $proposedDate)) {
            $error = 'Please select a valid Proposed Inspection Date.';
        } else {
            try {
                $date = DateTime::createFromFormat(
                    'Y-m-d',
                    $proposedDate,
                    new DateTimeZone('Asia/Kolkata')
                );

                if (!$date || $date->format('Y-m-d') !== $proposedDate) {
                    throw new Exception('Invalid Proposed Inspection Date.');
                }

                $today = new DateTime(
                    'today',
                    new DateTimeZone('Asia/Kolkata')
                );

                if ($date < $today) {
                    throw new Exception(
                        'Proposed Inspection Date cannot be in the past.'
                    );
                }

                $stmt = $conn->prepare("
                    SELECT id, saf_no, status
                    FROM saf_contracts
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to check SAF: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $safId);
                $stmt->execute();

                $saf = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$saf) {
                    throw new Exception('SAF not found.');
                }

                if ($saf['status'] !== 'Pending Admin Review') {
                    throw new Exception(
                        'This SAF is not pending Admin review.'
                    );
                }

                $update = $conn->prepare("
                    UPDATE saf_contracts
                    SET
                        status = 'Pending Owner Acceptance',
                        proposed_inspection_date = ?,
                        admin_reviewed_at = NOW(),
                        forwarded_to_owner_at = NOW(),
                        updated_at = NOW()
                    WHERE
                        id = ?
                        AND status = 'Pending Admin Review'
                ");

                if (!$update) {
                    throw new Exception(
                        'Unable to prepare SAF forwarding: ' . $conn->error
                    );
                }

                $update->bind_param('si', $proposedDate, $safId);

                if (!$update->execute() || $update->affected_rows !== 1) {
                    throw new Exception('SAF could not be forwarded.');
                }

                $update->close();

                $success =
                    'SAF ' . $saf['saf_no'] .
                    ' forwarded to Owner with Proposed Inspection Date ' .
                    safDateOnly($proposedDate) . '.';

            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
    }
}

/* =========================================================
   ASSIGN / REASSIGN INSPECTOR — ASSET SPECIFIC
   ---------------------------------------------------------
   IMPORTANT:
   - Every selected asset can have a DIFFERENT Inspector.
   - Example:
       Asset A / Location 1 → Inspector 1
       Asset B / Location 2 → Inspector 2
       Asset C / Location 3 → Inspector 3
   - Reassign is per particular asset.
   - Existing assignment row is updated; no new duplicate
     assignment is created for the same SAF + Asset.
   - Once an inspection has started/completed, reassignment
     is blocked so active inspection data is not overwritten.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['assign_saf_inspectors'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $safId = (int)($_POST['saf_id'] ?? 0);
        $assetInspectors = $_POST['asset_inspector'] ?? [];

        if ($safId <= 0) {
            $error = 'Invalid SAF selected.';
        } elseif (!is_array($assetInspectors) || !$assetInspectors) {
            $error = 'Please assign an Inspector to at least one asset.';
        } else {
            try {
                ensureSafInspectionJobType($conn);

                $stmt = $conn->prepare("
                    SELECT
                        id,
                        request_id,
                        psga_no,
                        saf_no,
                        owner_id,
                        asset_id,
                        service_id,
                        status
                    FROM saf_contracts
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to load SAF: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $safId);
                $stmt->execute();
                $saf = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$saf) {
                    throw new Exception('SAF not found.');
                }

                if ($saf['status'] !== 'Accepted') {
                    throw new Exception(
                        'Inspector can only be assigned after Owner acceptance.'
                    );
                }

                $assignedServiceCode = strtoupper(
                    trim((string)($saf['service_code'] ?? ''))
                );

                if (safIsDgsServiceCode($assignedServiceCode)) {
                    throw new Exception(
                        'Digital Governance Support does not require Inspector assignment.'
                    );
                }

                /*
                 * Load all assets belonging to this SAF's Service Request.
                 */
                $selectedAssets = [];

                if (
                    tableExists($conn, 'service_request_assets') &&
                    tableExists($conn, 'assets')
                ) {
                    $assetStmt = $conn->prepare("
                        SELECT
                            sra.asset_id,
                            a.display_asset_id,
                            a.asset_name,
                            a.location
                        FROM service_request_assets sra
                        INNER JOIN assets a
                            ON a.id = sra.asset_id
                        WHERE sra.request_id = ?
                        ORDER BY sra.id ASC, a.id ASC
                    ");

                    if ($assetStmt) {
                        $requestId = (int)$saf['request_id'];
                        $assetStmt->bind_param('i', $requestId);
                        $assetStmt->execute();

                        $assetResult = $assetStmt->get_result();

                        while ($asset = $assetResult->fetch_assoc()) {
                            $selectedAssets[(int)$asset['asset_id']] = $asset;
                        }

                        $assetStmt->close();
                    }
                }

                /*
                 * Fallback for older SAF records.
                 */
                if (!$selectedAssets) {
                    $fallbackId = (int)$saf['asset_id'];

                    if ($fallbackId > 0 && tableExists($conn, 'assets')) {
                        $fallbackStmt = $conn->prepare("
                            SELECT
                                id AS asset_id,
                                display_asset_id,
                                asset_name,
                                location
                            FROM assets
                            WHERE id = ?
                            LIMIT 1
                        ");

                        if ($fallbackStmt) {
                            $fallbackStmt->bind_param('i', $fallbackId);
                            $fallbackStmt->execute();

                            $fallback =
                                $fallbackStmt->get_result()->fetch_assoc();

                            $fallbackStmt->close();

                            if ($fallback) {
                                $selectedAssets[$fallbackId] = $fallback;
                            }
                        }
                    }
                }

                if (!$selectedAssets) {
                    throw new Exception(
                        'No selected assets were found for this SAF.'
                    );
                }

                /*
                 * Validate every submitted asset belongs to this SAF.
                 */
                $validatedAssignments = [];

                foreach ($assetInspectors as $assetIdRaw => $inspectorIdRaw) {
                    $assetId = (int)$assetIdRaw;
                    $inspectorId = (int)$inspectorIdRaw;

                    if ($assetId <= 0 || $inspectorId <= 0) {
                        continue;
                    }

                    if (!isset($selectedAssets[$assetId])) {
                        throw new Exception(
                            'Invalid asset selected for Inspector assignment.'
                        );
                    }

                    $validatedAssignments[$assetId] = $inspectorId;
                }

                if (!$validatedAssignments) {
                    throw new Exception(
                        'Please select an Inspector for at least one asset.'
                    );
                }

                /*
                 * Validate all Inspectors first.
                 */
                $verifiedInspectors = [];

                foreach ($validatedAssignments as $assetId => $inspectorId) {
                    if (isset($verifiedInspectors[$inspectorId])) {
                        continue;
                    }

                    $inspectorStmt = $conn->prepare("
                        SELECT id, name, email
                        FROM users
                        WHERE id = ?
                          AND role = 'inspector'
                        LIMIT 1
                    ");

                    if (!$inspectorStmt) {
                        throw new Exception(
                            'Unable to verify Inspector: ' . $conn->error
                        );
                    }

                    $inspectorStmt->bind_param('i', $inspectorId);
                    $inspectorStmt->execute();

                    $inspector =
                        $inspectorStmt->get_result()->fetch_assoc();

                    $inspectorStmt->close();

                    if (!$inspector) {
                        throw new Exception(
                            'Selected Inspector ID ' .
                            $inspectorId .
                            ' is not a valid Inspector.'
                        );
                    }

                    $verifiedInspectors[$inspectorId] = $inspector;
                }

                $conn->begin_transaction();

                try {
                    foreach ($validatedAssignments as $assetId => $inspectorId) {

                        /*
                         * Find existing SAF inspection job for this exact
                         * SAF + Asset.
                         */
                        $jobStmt = $conn->prepare("
                            SELECT id, status, technician_id
                            FROM maintenance_jobs
                            WHERE saf_id = ?
                              AND asset_id = ?
                              AND job_type = 'saf_inspection'
                            ORDER BY id DESC
                            LIMIT 1
                        ");

                        if (!$jobStmt) {
                            throw new Exception(
                                'Unable to check SAF inspection job: ' .
                                $conn->error
                            );
                        }

                        $jobStmt->bind_param('ii', $safId, $assetId);
                        $jobStmt->execute();

                        $job = $jobStmt->get_result()->fetch_assoc();
                        $jobStmt->close();

                        if ($job) {
                            $jobId = (int)$job['id'];

                            if (in_array(
                                (string)$job['status'],
                                [
                                    'in_progress',
                                    'pending_owner',
                                    'approved',
                                    'closed'
                                ],
                                true
                            )) {
                                throw new Exception(
                                    'Asset ' .
                                    ($selectedAssets[$assetId]['display_asset_id'] ?? ('#' . $assetId)) .
                                    ' already has inspection work in progress/completed. ' .
                                    'It cannot be reassigned from this screen.'
                                );
                            }

                            $hasUpdatedAt = columnExists(
                                $conn,
                                'maintenance_jobs',
                                'updated_at'
                            );

                            if ($hasUpdatedAt) {
                                $updateJob = $conn->prepare("
                                    UPDATE maintenance_jobs
                                    SET
                                        service_id = ?,
                                        asset_id = ?,
                                        owner_id = ?,
                                        technician_id = ?,
                                        status = 'assigned',
                                        updated_at = NOW()
                                    WHERE id = ?
                                ");
                            } else {
                                $updateJob = $conn->prepare("
                                    UPDATE maintenance_jobs
                                    SET
                                        service_id = ?,
                                        asset_id = ?,
                                        owner_id = ?,
                                        technician_id = ?,
                                        status = 'assigned'
                                    WHERE id = ?
                                ");
                            }

                            if (!$updateJob) {
                                throw new Exception(
                                    'Unable to update SAF inspection job: ' .
                                    $conn->error
                                );
                            }

                            $serviceId = (int)$saf['service_id'];
                            $ownerId = (int)$saf['owner_id'];

                            $updateJob->bind_param(
                                'iiiii',
                                $serviceId,
                                $assetId,
                                $ownerId,
                                $inspectorId,
                                $jobId
                            );

                            if (!$updateJob->execute()) {
                                throw new Exception(
                                    'Unable to update SAF inspection job: ' .
                                    $updateJob->error
                                );
                            }

                            $updateJob->close();

                        } else {
                            /*
                             * First-time assignment for this asset.
                             */
                            $hasUpdatedAt = columnExists(
                                $conn,
                                'maintenance_jobs',
                                'updated_at'
                            );

                            if ($hasUpdatedAt) {
                                $insertJob = $conn->prepare("
                                    INSERT INTO maintenance_jobs
                                    (
                                        saf_id,
                                        service_id,
                                        asset_id,
                                        technician_id,
                                        owner_id,
                                        status,
                                        job_type,
                                        created_at,
                                        updated_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        'assigned',
                                        'saf_inspection',
                                        NOW(),
                                        NOW()
                                    )
                                ");
                            } else {
                                $insertJob = $conn->prepare("
                                    INSERT INTO maintenance_jobs
                                    (
                                        saf_id,
                                        service_id,
                                        asset_id,
                                        technician_id,
                                        owner_id,
                                        status,
                                        job_type,
                                        created_at
                                    )
                                    VALUES
                                    (
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        ?,
                                        'assigned',
                                        'saf_inspection',
                                        NOW()
                                    )
                                ");
                            }

                            if (!$insertJob) {
                                throw new Exception(
                                    'Unable to create SAF inspection job: ' .
                                    $conn->error
                                );
                            }

                            $serviceId = (int)$saf['service_id'];
                            $ownerId = (int)$saf['owner_id'];

                            $insertJob->bind_param(
                                'iiiii',
                                $safId,
                                $serviceId,
                                $assetId,
                                $inspectorId,
                                $ownerId
                            );

                            if (!$insertJob->execute()) {
                                throw new Exception(
                                    'Unable to create SAF inspection job: ' .
                                    $insertJob->error
                                );
                            }

                            $jobId = (int)$insertJob->insert_id;
                            $insertJob->close();
                        }

                        /*
                         * One active assignment per SAF + Asset.
                         * Existing assignment is UPDATED for reassignment.
                         */
                        $assignmentStmt = $conn->prepare("
                            SELECT
                                id,
                                inspector_id,
                                status
                            FROM inspector_assignments
                            WHERE job_id = ?
                              AND asset_id = ?
                            ORDER BY id DESC
                            LIMIT 1
                        ");

                        if (!$assignmentStmt) {
                            throw new Exception(
                                'Unable to check Inspector assignment: ' .
                                $conn->error
                            );
                        }

                        $assignmentStmt->bind_param('ii', $jobId, $assetId);
                        $assignmentStmt->execute();

                        $existingAssignment =
                            $assignmentStmt->get_result()->fetch_assoc();

                        $assignmentStmt->close();

                        $ownerId = (int)$saf['owner_id'];
                        $serviceId = (int)$saf['service_id'];

                        if ($existingAssignment) {

                            $assignmentId =
                                (int)$existingAssignment['id'];

                            $updateAssignment = $conn->prepare("
                                UPDATE inspector_assignments
                                SET
                                    inspector_id = ?,
                                    owner_id = ?,
                                    service_id = ?,
                                    status = 'assigned',
                                    assigned_by = ?,
                                    assigned_at = NOW()
                                WHERE id = ?
                            ");

                            if (!$updateAssignment) {
                                throw new Exception(
                                    'Unable to update Inspector assignment: ' .
                                    $conn->error
                                );
                            }

                            $updateAssignment->bind_param(
                                'iiiii',
                                $inspectorId,
                                $ownerId,
                                $serviceId,
                                $adminId,
                                $assignmentId
                            );

                            if (!$updateAssignment->execute()) {
                                throw new Exception(
                                    'Unable to update Inspector assignment: ' .
                                    $updateAssignment->error
                                );
                            }

                            $updateAssignment->close();

                        } else {

                            $insertAssignment = $conn->prepare("
                                INSERT INTO inspector_assignments
                                (
                                    job_id,
                                    inspector_id,
                                    asset_id,
                                    owner_id,
                                    service_id,
                                    status,
                                    assigned_by,
                                    assigned_at
                                )
                                VALUES
                                (
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    ?,
                                    'assigned',
                                    ?,
                                    NOW()
                                )
                            ");

                            if (!$insertAssignment) {
                                throw new Exception(
                                    'Unable to create Inspector assignment: ' .
                                    $conn->error
                                );
                            }

                            $insertAssignment->bind_param(
                                'iiiiii',
                                $jobId,
                                $inspectorId,
                                $assetId,
                                $ownerId,
                                $serviceId,
                                $adminId
                            );

                            if (!$insertAssignment->execute()) {
                                throw new Exception(
                                    'Unable to create Inspector assignment: ' .
                                    $insertAssignment->error
                                );
                            }

                            $insertAssignment->close();
                        }
                    }

                    $conn->commit();

                    /* =====================================================
                       OWNER EMAIL — INSPECTOR ASSIGNMENT
                       -----------------------------------------------
                       DB commit is complete before email is attempted.
                       Email failure must never rollback the assignment.
                    ===================================================== */
                    try {
                        $ownerAssignmentRows = [];

                        $ownerMailStmt = $conn->prepare("
                            SELECT
                                s.saf_no,
                                s.psga_no,
                                s.proposed_inspection_date,
                                s.service_code,
                                s.service_name,
                                u.name AS owner_name,
                                u.email AS owner_email
                            FROM saf_contracts s
                            LEFT JOIN users u
                                ON u.id = s.owner_id
                            WHERE s.id = ?
                            LIMIT 1
                        ");

                        if ($ownerMailStmt) {
                            $ownerMailStmt->bind_param('i', $safId);
                            $ownerMailStmt->execute();
                            $ownerSaf = $ownerMailStmt->get_result()->fetch_assoc();
                            $ownerMailStmt->close();

                            if ($ownerSaf) {
                                foreach ($validatedAssignments as $assetId => $inspectorId) {
                                    $asset = $selectedAssets[$assetId] ?? [];
                                    $inspector = $verifiedInspectors[$inspectorId] ?? [];

                                    $phone = '';
                                    $phoneStmt = $conn->prepare("SELECT phone FROM users WHERE id = ? LIMIT 1");
                                    if ($phoneStmt) {
                                        $phoneStmt->bind_param('i', $inspectorId);
                                        $phoneStmt->execute();
                                        $phoneRow = $phoneStmt->get_result()->fetch_assoc();
                                        $phoneStmt->close();
                                        $phone = trim((string)($phoneRow['phone'] ?? ''));
                                    }

                                    $ownerAssignmentRows[] = [
                                        'asset_id'         => (string)$assetId,
                                        'display_asset_id' => (string)($asset['display_asset_id'] ?? ''),
                                        'asset_name'       => (string)($asset['asset_name'] ?? ''),
                                        'location'         => (string)($asset['location'] ?? ''),
                                        'inspector_name'   => (string)($inspector['name'] ?? 'Inspector'),
                                        'inspector_email'  => (string)($inspector['email'] ?? ''),
                                        'inspector_phone'  => $phone
                                    ];
                                }

                                if ($ownerAssignmentRows) {
                                    $serviceLabel = trim((string)($ownerSaf['service_name'] ?? ''));
                                    if ($serviceLabel === '') {
                                        $serviceLabel = trim((string)($ownerSaf['service_code'] ?? ''));
                                    }

                                    sendNletaSafInspectorAssignmentOwnerEmail(
                                        (int)$safId,
                                        (string)($ownerSaf['saf_no'] ?? ''),
                                        (string)($ownerSaf['psga_no'] ?? ''),
                                        (string)($ownerSaf['owner_name'] ?? ''),
                                        (string)($ownerSaf['owner_email'] ?? ''),
                                        $serviceLabel,
                                        (string)($ownerSaf['proposed_inspection_date'] ?? ''),
                                        $ownerAssignmentRows,
                                        false
                                    );
                                }
                            }
                        }
                    } catch (Throwable $mailError) {
                        error_log(
                            'NLETA Inspector Assignment Owner Email Integration Error: ' .
                            $mailError->getMessage()
                        );
                    }

                    $success =
                        count($validatedAssignments) .
                        ' asset inspection assignment(s) saved successfully.';

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
   REASSIGN ONE INSPECTOR — PARTICULAR ASSET
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['reassign_saf_inspector'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $safId = (int)($_POST['saf_id'] ?? 0);
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $newInspectorId = (int)($_POST['inspector_id'] ?? 0);

        if ($safId <= 0 || $assetId <= 0 || $newInspectorId <= 0) {
            $error = 'Invalid SAF, asset or Inspector selected.';
        } else {
            try {
                $stmt = $conn->prepare("
                    SELECT
                        id,
                        request_id,
                        owner_id,
                        service_id,
                        status
                    FROM saf_contracts
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$stmt) {
                    throw new Exception(
                        'Unable to load SAF: ' . $conn->error
                    );
                }

                $stmt->bind_param('i', $safId);
                $stmt->execute();
                $saf = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$saf) {
                    throw new Exception('SAF not found.');
                }

                if ($saf['status'] !== 'Accepted') {
                    throw new Exception(
                        'Inspector can only be reassigned after Owner acceptance.'
                    );
                }

                $reassignServiceCode = strtoupper(
                    trim((string)($saf['service_code'] ?? ''))
                );

                if (safIsDgsServiceCode($reassignServiceCode)) {
                    throw new Exception(
                        'Digital Governance Support does not require Inspector assignment.'
                    );
                }

                $inspectorStmt = $conn->prepare("
                    SELECT id, name, email
                    FROM users
                    WHERE id = ?
                      AND role = 'inspector'
                    LIMIT 1
                ");

                if (!$inspectorStmt) {
                    throw new Exception(
                        'Unable to verify Inspector: ' . $conn->error
                    );
                }

                $inspectorStmt->bind_param('i', $newInspectorId);
                $inspectorStmt->execute();

                $newInspector =
                    $inspectorStmt->get_result()->fetch_assoc();

                $inspectorStmt->close();

                if (!$newInspector) {
                    throw new Exception(
                        'The selected user is not a valid Inspector.'
                    );
                }

                /*
                 * Confirm that this asset belongs to the SAF.
                 */
                $assetBelongs = false;

                if (
                    tableExists($conn, 'service_request_assets') &&
                    tableExists($conn, 'assets')
                ) {
                    $assetCheck = $conn->prepare("
                        SELECT a.id
                        FROM service_request_assets sra
                        INNER JOIN assets a
                            ON a.id = sra.asset_id
                        WHERE sra.request_id = ?
                          AND a.id = ?
                        LIMIT 1
                    ");

                    if ($assetCheck) {
                        $requestId = (int)$saf['request_id'];
                        $assetCheck->bind_param(
                            'ii',
                            $requestId,
                            $assetId
                        );
                        $assetCheck->execute();

                        $assetBelongs =
                            (bool)$assetCheck->get_result()->fetch_assoc();

                        $assetCheck->close();
                    }
                }

                if (!$assetBelongs && $assetId === (int)$saf['asset_id']) {
                    $assetBelongs = true;
                }

                if (!$assetBelongs) {
                    throw new Exception(
                        'The selected asset does not belong to this SAF.'
                    );
                }

                $jobStmt = $conn->prepare("
                    SELECT id, status, technician_id
                    FROM maintenance_jobs
                    WHERE saf_id = ?
                      AND asset_id = ?
                      AND job_type = 'saf_inspection'
                    ORDER BY id DESC
                    LIMIT 1
                ");

                if (!$jobStmt) {
                    throw new Exception(
                        'Unable to find asset inspection job: ' .
                        $conn->error
                    );
                }

                $jobStmt->bind_param('ii', $safId, $assetId);
                $jobStmt->execute();

                $job = $jobStmt->get_result()->fetch_assoc();
                $jobStmt->close();

                if (!$job) {
                    throw new Exception(
                        'No Inspector assignment exists for this asset yet.'
                    );
                }

                if (in_array(
                    (string)$job['status'],
                    [
                        'in_progress',
                        'pending_owner',
                        'approved',
                        'closed'
                    ],
                    true
                )) {
                    throw new Exception(
                        'This asset inspection has already started or progressed. ' .
                        'Reassignment is blocked to protect the existing inspection.'
                    );
                }

                $assignmentStmt = $conn->prepare("
                    SELECT id, inspector_id
                    FROM inspector_assignments
                    WHERE job_id = ?
                      AND asset_id = ?
                    ORDER BY id DESC
                    LIMIT 1
                ");

                if (!$assignmentStmt) {
                    throw new Exception(
                        'Unable to find Inspector assignment: ' .
                        $conn->error
                    );
                }

                $jobId = (int)$job['id'];
                $assignmentStmt->bind_param('ii', $jobId, $assetId);
                $assignmentStmt->execute();

                $assignment =
                    $assignmentStmt->get_result()->fetch_assoc();

                $assignmentStmt->close();

                if (!$assignment) {
                    throw new Exception(
                        'Inspector assignment was not found for this asset.'
                    );
                }

                $oldInspectorId = (int)$assignment['inspector_id'];

                if ($oldInspectorId === $newInspectorId) {
                    throw new Exception(
                        'The selected Inspector is already assigned to this asset.'
                    );
                }

                $conn->begin_transaction();

                try {
                    $hasUpdatedAt = columnExists(
                        $conn,
                        'maintenance_jobs',
                        'updated_at'
                    );

                    if ($hasUpdatedAt) {
                        $updateJob = $conn->prepare("
                            UPDATE maintenance_jobs
                            SET
                                technician_id = ?,
                                status = 'assigned',
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                    } else {
                        $updateJob = $conn->prepare("
                            UPDATE maintenance_jobs
                            SET
                                technician_id = ?,
                                status = 'assigned'
                            WHERE id = ?
                        ");
                    }

                    if (!$updateJob) {
                        throw new Exception(
                            'Unable to update inspection job: ' . $conn->error
                        );
                    }

                    $updateJob->bind_param(
                        'ii',
                        $newInspectorId,
                        $jobId
                    );

                    if (!$updateJob->execute()) {
                        throw new Exception(
                            'Unable to reassign inspection job: ' .
                            $updateJob->error
                        );
                    }

                    $updateJob->close();

                    $updateAssignment = $conn->prepare("
                        UPDATE inspector_assignments
                        SET
                            inspector_id = ?,
                            status = 'assigned',
                            assigned_by = ?,
                            assigned_at = NOW()
                        WHERE id = ?
                    ");

                    if (!$updateAssignment) {
                        throw new Exception(
                            'Unable to update Inspector assignment: ' .
                            $conn->error
                        );
                    }

                    $updateAssignment->bind_param(
                        'iii',
                        $newInspectorId,
                        $adminId,
                        $assignment['id']
                    );

                    if (!$updateAssignment->execute()) {
                        throw new Exception(
                            'Unable to reassign Inspector: ' .
                            $updateAssignment->error
                        );
                    }

                    $updateAssignment->close();

                    $conn->commit();

                    /* =====================================================
                       OWNER EMAIL — INSPECTOR REASSIGNED
                       -----------------------------------------------
                       DB commit is complete before email is attempted.
                    ===================================================== */
                    try {
                        $ownerMailStmt = $conn->prepare("
                            SELECT
                                s.saf_no,
                                s.psga_no,
                                s.proposed_inspection_date,
                                s.service_code,
                                s.service_name,
                                u.name AS owner_name,
                                u.email AS owner_email,
                                a.display_asset_id,
                                a.asset_name,
                                a.location,
                                i.name AS inspector_name,
                                i.email AS inspector_email,
                                i.phone AS inspector_phone
                            FROM saf_contracts s
                            LEFT JOIN users u
                                ON u.id = s.owner_id
                            LEFT JOIN assets a
                                ON a.id = ?
                            LEFT JOIN users i
                                ON i.id = ?
                            WHERE s.id = ?
                            LIMIT 1
                        ");

                        if ($ownerMailStmt) {
                            $ownerMailStmt->bind_param(
                                'iii',
                                $assetId,
                                $newInspectorId,
                                $safId
                            );
                            $ownerMailStmt->execute();
                            $ownerSaf = $ownerMailStmt->get_result()->fetch_assoc();
                            $ownerMailStmt->close();

                            if ($ownerSaf) {
                                $serviceLabel = trim((string)($ownerSaf['service_name'] ?? ''));
                                if ($serviceLabel === '') {
                                    $serviceLabel = trim((string)($ownerSaf['service_code'] ?? ''));
                                }

                                sendNletaSafInspectorAssignmentOwnerEmail(
                                    (int)$safId,
                                    (string)($ownerSaf['saf_no'] ?? ''),
                                    (string)($ownerSaf['psga_no'] ?? ''),
                                    (string)($ownerSaf['owner_name'] ?? ''),
                                    (string)($ownerSaf['owner_email'] ?? ''),
                                    $serviceLabel,
                                    (string)($ownerSaf['proposed_inspection_date'] ?? ''),
                                    [[
                                        'asset_id'         => (string)$assetId,
                                        'display_asset_id' => (string)($ownerSaf['display_asset_id'] ?? ''),
                                        'asset_name'       => (string)($ownerSaf['asset_name'] ?? ''),
                                        'location'         => (string)($ownerSaf['location'] ?? ''),
                                        'inspector_name'   => (string)($ownerSaf['inspector_name'] ?? 'Inspector'),
                                        'inspector_email'  => (string)($ownerSaf['inspector_email'] ?? ''),
                                        'inspector_phone'  => (string)($ownerSaf['inspector_phone'] ?? '')
                                    ]],
                                    true
                                );
                            }
                        }
                    } catch (Throwable $mailError) {
                        error_log(
                            'NLETA Inspector Reassignment Owner Email Integration Error: ' .
                            $mailError->getMessage()
                        );
                    }

                    $success =
                        'Inspector reassigned successfully to ' .
                        $newInspector['name'] .
                        ' for this particular asset.';

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
   DELETE SAF — ADMIN ACCESS
   ---------------------------------------------------------
   Deletes SAF + its SAF inspection records.
   PSGA is never deleted.
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_saf'])
) {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $safId = (int)($_POST['saf_id'] ?? 0);

        if ($safId <= 0) {
            $error = 'Invalid SAF selected.';
        } else {
            try {
                $check = $conn->prepare("
                    SELECT id, saf_no
                    FROM saf_contracts
                    WHERE id = ?
                    LIMIT 1
                ");

                if (!$check) {
                    throw new Exception(
                        'Unable to check SAF: ' . $conn->error
                    );
                }

                $check->bind_param('i', $safId);
                $check->execute();

                $safToDelete = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$safToDelete) {
                    throw new Exception('SAF not found.');
                }

                $conn->begin_transaction();

                try {
                    $jobIds = [];

                    if (
                        tableExists($conn, 'maintenance_jobs') &&
                        columnExists($conn, 'maintenance_jobs', 'saf_id')
                    ) {
                        $jobs = $conn->prepare("
                            SELECT id
                            FROM maintenance_jobs
                            WHERE saf_id = ?
                              AND job_type = 'saf_inspection'
                        ");

                        if ($jobs) {
                            $jobs->bind_param('i', $safId);
                            $jobs->execute();

                            $jobResult = $jobs->get_result();

                            while ($job = $jobResult->fetch_assoc()) {
                                $jobIds[] = (int)$job['id'];
                            }

                            $jobs->close();
                        }
                    }

                    foreach ($jobIds as $jobId) {
                        if (
                            tableExists($conn, 'report_requests') &&
                            columnExists($conn, 'report_requests', 'job_id')
                        ) {
                            $q = $conn->prepare(
                                "DELETE FROM report_requests WHERE job_id = ?"
                            );
                            if ($q) {
                                $q->bind_param('i', $jobId);
                                $q->execute();
                                $q->close();
                            }
                        }

                        if (
                            tableExists($conn, 'inspector_assignments') &&
                            columnExists($conn, 'inspector_assignments', 'job_id')
                        ) {
                            $q = $conn->prepare(
                                "DELETE FROM inspector_assignments WHERE job_id = ?"
                            );
                            if ($q) {
                                $q->bind_param('i', $jobId);
                                $q->execute();
                                $q->close();
                            }
                        }

                        $q = $conn->prepare(
                            "DELETE FROM maintenance_jobs WHERE id = ? AND saf_id = ?"
                        );

                        if ($q) {
                            $q->bind_param('ii', $jobId, $safId);
                            $q->execute();
                            $q->close();
                        }
                    }

                    $deleteSaf = $conn->prepare("
                        DELETE FROM saf_contracts
                        WHERE id = ?
                        LIMIT 1
                    ");

                    if (!$deleteSaf) {
                        throw new Exception(
                            'Unable to delete SAF: ' . $conn->error
                        );
                    }

                    $deleteSaf->bind_param('i', $safId);

                    if (!$deleteSaf->execute() || $deleteSaf->affected_rows !== 1) {
                        throw new Exception('SAF could not be deleted.');
                    }

                    $deleteSaf->close();

                    $conn->commit();

                    $success =
                        'SAF ' . $safToDelete['saf_no'] .
                        ' deleted successfully. Permanent PSGA remains safe.';

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
   LOAD SAF LIST
   ---------------------------------------------------------
   IMPORTANT:
   - Each SAF is ASSET + SERVICE specific.
   - Never join service_request_assets here because that would
     display ALL assets belonging to the FSO inside every SAF.
   - Service name is resolved from the service stored on THIS SAF
     (service_catalog first, pricing master as fallback).
========================================================= */

$safs = [];

$serviceCatalogExists = tableExists($conn, 'service_catalog');
$pricingMasterExists  = tableExists($conn, 'nleta_pricing_master');

/*
 * IMPORTANT:
 * Do NOT JOIN nleta_pricing_master directly here.
 * That table can contain multiple active pricing rows for the same
 * service code. A normal JOIN would multiply one SAF row into many
 * identical SAF rows on the screen.
 *
 * We therefore resolve the service name with scalar subqueries and
 * keep exactly ONE database row for each saf_contracts.id.
 */
if ($serviceCatalogExists) {
    $catalogServiceSelect = "(
        SELECT sc1.service_name
        FROM service_catalog sc1
        WHERE sc1.id = s.service_id
        LIMIT 1
    ) AS catalog_service_name";
} else {
    $catalogServiceSelect = "'' AS catalog_service_name";
}

if ($pricingMasterExists) {
    $pricingServiceSelect = "(
        SELECT pm1.service_name
        FROM nleta_pricing_master pm1
        WHERE pm1.service_code = s.service_code
          AND pm1.is_active = 1
        ORDER BY pm1.id ASC
        LIMIT 1
    ) AS pricing_service_name";
} else {
    $pricingServiceSelect = "'' AS pricing_service_name";
}

$result = $conn->query("\n    SELECT\n        s.id,\n        s.psga_no,\n        s.saf_no,\n        s.request_id,\n        s.owner_id,\n        s.asset_id,\n        s.service_id,\n        s.service_code,\n        s.service_name,\n        {$catalogServiceSelect},\n        {$pricingServiceSelect},\n        s.saf_type,\n        s.status,\n        s.generated_at,\n        s.admin_reviewed_at,\n        s.forwarded_to_owner_at,\n        s.owner_accepted_at,\n        s.proposed_inspection_date,\n        s.dgs_activation_status,\n        s.dgs_activated_by,\n        s.dgs_activated_at,\n\n        u.name AS owner_name,\n        u.email AS owner_email,\n\n        a.asset_name,\n        a.display_asset_id,\n        a.location\n\n    FROM saf_contracts s\n\n    LEFT JOIN users u\n        ON u.id = s.owner_id\n\n    LEFT JOIN assets a\n        ON a.id = s.asset_id\n\n    ORDER BY\n        CASE s.status\n            WHEN 'Pending Admin Review' THEN 1\n            WHEN 'Correction Required' THEN 2\n            WHEN 'Pending Owner Acceptance' THEN 3\n            WHEN 'Accepted' THEN 4\n            ELSE 5\n        END,\n        s.id DESC\n");

if (!$result) {
    $error = $error !== ''
        ? $error
        : 'Unable to load SAF Contracts: ' . $conn->error;
} else {
    while ($row = $result->fetch_assoc()) {
        /*
         * Resolve the service exactly as selected for this request.
         * Do not show technical "Service ID X" text to Admin.
         */
        $catalogName = trim((string)($row['catalog_service_name'] ?? ''));
        $pricingName = trim((string)($row['pricing_service_name'] ?? ''));
        $storedName  = trim((string)($row['service_name'] ?? ''));

        if ($catalogName !== '') {
            $row['display_service_name'] = $catalogName;
        } elseif ($pricingName !== '') {
            $row['display_service_name'] = $pricingName;
        } elseif ($storedName !== '' && !preg_match('/^Service\s+ID\s+\d+$/i', $storedName)) {
            $row['display_service_name'] = $storedName;
        } elseif (trim((string)($row['service_code'] ?? '')) !== '') {
            $row['display_service_name'] = trim((string)$row['service_code']);
        } else {
            $row['display_service_name'] = 'Service not configured';
        }

        /*
         * IMPORTANT:
         * Every SAF is now SERVICE-SPECIFIC.
         *
         * Example:
         *   Asset 139 + PAT = one SAF
         *   Asset 139 + SAT = another SAF
         *   Asset 140 + PAT = another SAF
         *
         * Therefore the SAF list must display ONLY the service stored
         * on this saf_contracts row, not all services selected in Phase-4.
         */
        $rowServiceCode = strtoupper(
            trim((string)($row['service_code'] ?? ''))
        );
        $rowServiceName = trim(
            (string)($row['display_service_name'] ?? '')
        );

        if ($rowServiceName === '' || $rowServiceName === 'Service not configured') {
            $rowServiceName = $rowServiceCode !== ''
                ? $rowServiceCode
                : 'Service not configured';
        }

        $row['display_service_labels'] = [
            $rowServiceCode !== '' && $rowServiceName !== ''
                ? $rowServiceCode . ' - ' . $rowServiceName
                : $rowServiceName
        ];

        $safs[] = $row;
    }
}

/* =========================================================
   LOAD ASSETS COVERED BY EACH SAF
   ---------------------------------------------------------
   IMPORTANT:
   - PAT / SAT / SCI SAF → show ONLY the asset stored on that SAF.
   - DGS / DG SAF → show ALL assets selected in Phase-4 for the
     same Service Request.
   - DGS uses one governance SAF for the FSO/PSGA, so its
     saf_contracts.asset_id is only an anchor and must NOT be used
     as the only displayed asset.
========================================================= */

$safAssetsBySaf = [];

foreach ($safs as $safRow) {
    $sid = (int)($safRow['id'] ?? 0);
    $aid = (int)($safRow['asset_id'] ?? 0);
    $requestId = (int)($safRow['request_id'] ?? 0);
    $serviceCode = strtoupper(
        trim((string)($safRow['service_code'] ?? ''))
    );

    if ($sid <= 0) {
        continue;
    }

    $safAssetsBySaf[$sid] = [];

    /*
     * DGS is one governance SAF for the complete Phase-4
     * asset selection. Load every selected asset for this request.
     */
    if (
        safIsDgsServiceCode($serviceCode) &&
        $requestId > 0 &&
        tableExists($conn, 'service_request_assets') &&
        tableExists($conn, 'assets')
    ) {
        $assetStmt = $conn->prepare("
            SELECT DISTINCT
                sra.asset_id,
                a.display_asset_id,
                a.asset_name,
                a.location
            FROM service_request_assets sra
            INNER JOIN assets a
                ON a.id = sra.asset_id
            WHERE sra.request_id = ?
            ORDER BY a.id ASC
        ");

        if ($assetStmt) {
            $assetStmt->bind_param('i', $requestId);
            $assetStmt->execute();

            $assetResult = $assetStmt->get_result();

            while ($asset = $assetResult->fetch_assoc()) {
                $assetId = (int)($asset['asset_id'] ?? 0);

                if ($assetId <= 0) {
                    continue;
                }

                $safAssetsBySaf[$sid][$assetId] = [
                    'asset_id' => $assetId,
                    'display_asset_id' => $asset['display_asset_id'] ?? '',
                    'asset_name' => $asset['asset_name'] ?? '',
                    'location' => $asset['location'] ?? ''
                ];
            }

            $assetStmt->close();
        }
    }

    /*
     * PAT / SAT / SCI — keep the existing service-specific
     * one-SAF-per-asset behaviour.
     *
     * Also acts as fallback for DGS if the Phase-4 asset relation
     * is unavailable/empty.
     */
    if (!$safAssetsBySaf[$sid] && $aid > 0) {
        $safAssetsBySaf[$sid][$aid] = [
            'asset_id' => $aid,
            'display_asset_id' => $safRow['display_asset_id'] ?? '',
            'asset_name' => $safRow['asset_name'] ?? '',
            'location' => $safRow['location'] ?? ''
        ];
    }
}

/* =========================================================
   LOAD INSPECTOR ASSIGNMENTS — PER SAF + PER ASSET
========================================================= */

$inspectorAssignmentAssets = [];

if (tableExists($conn, 'inspector_assignments')) {
    $assignmentResult = $conn->query("
        SELECT
            ia.id,
            ia.job_id,
            mj.saf_id,
            ia.inspector_id,
            ia.asset_id,
            ia.owner_id,
            ia.service_id,
            ia.status,
            ia.assigned_by,
            ia.assigned_at,
            ia.started_at,
            ia.submitted_at,
            ia.completed_at,
            u.name AS inspector_name,
            u.email AS inspector_email
        FROM inspector_assignments ia
        LEFT JOIN maintenance_jobs mj
            ON mj.id = ia.job_id
        LEFT JOIN users u
            ON u.id = ia.inspector_id
        ORDER BY ia.id DESC
    ");

    if ($assignmentResult) {
        while ($assignment = $assignmentResult->fetch_assoc()) {
            $sid = (int)($assignment['saf_id'] ?? 0);
            $aid = (int)($assignment['asset_id'] ?? 0);

            if ($sid <= 0 || $aid <= 0) {
                continue;
            }

            if (!isset($inspectorAssignmentAssets[$sid])) {
                $inspectorAssignmentAssets[$sid] = [];
            }

            /*
             * Latest assignment for each SAF + Asset is the active
             * assignment. This allows Admin to reassign the same asset
             * without creating duplicate active assignments.
             */
            if (!isset($inspectorAssignmentAssets[$sid][$aid])) {
                $inspectorAssignmentAssets[$sid][$aid] = $assignment;
            }
        }
    }
}

/* =========================================================
   LOAD INSPECTORS
========================================================= */

$inspectors = [];

$inspectorResult = $conn->query("
    SELECT id, name, email, phone
    FROM users
    WHERE role = 'inspector'
    ORDER BY name ASC
");

if ($inspectorResult) {
    while ($inspector = $inspectorResult->fetch_assoc()) {
        $inspectors[] = $inspector;
    }
}

/* =========================================================
   LOAD CONFIRMED PSGAs WITHOUT SAF FOR GENERATION
========================================================= */

$confirmedPsgaRows = [];

/*
 * IMPORTANT FOR MULTI-SERVICE:
 * A PSGA remains eligible until EVERY SAF-eligible
 * service × asset combination has been generated.
 *
 * Example:
 *   2 assets + PAT + SAT
 *   Required = 4 SAFs.
 *
 * We therefore do the final missing-combination check in PHP
 * instead of comparing only asset counts.
 */
$confirmedResult = $conn->query("
    SELECT
        c.PSGA_ID, c.PSGA_No, c.Owner_ID, c.Status,
        COALESCE(c.Owner_Name, u.name) AS owner_name,
        u.email AS owner_email,
        o.id AS offer_id, o.offer_no, o.request_id, sr.request_no,
        COUNT(DISTINCT sra.asset_id) AS total_assets
    FROM contracts c
    LEFT JOIN users u ON u.id = c.Owner_ID
    INNER JOIN nleta_offer_master o ON o.psga_no = c.PSGA_No
    LEFT JOIN service_requests sr ON sr.id = o.request_id
    LEFT JOIN service_request_assets sra ON sra.request_id = o.request_id
    WHERE c.Status = 'Confirmed'
    GROUP BY c.PSGA_ID, c.PSGA_No, c.Owner_ID, c.Status,
             c.Owner_Name, u.name, u.email,
             o.id, o.offer_no, o.request_id, sr.request_no
    ORDER BY c.PSGA_ID DESC, o.id DESC
");

if ($confirmedResult) {
    while ($r = $confirmedResult->fetch_assoc()) {
        $requestId = (int)($r['request_id'] ?? 0);
        $fallbackServiceId = 0;

        $requestFallback = $conn->prepare("
            SELECT service_id
            FROM service_requests
            WHERE id = ?
            LIMIT 1
        ");
        if ($requestFallback) {
            $requestFallback->bind_param('i', $requestId);
            $requestFallback->execute();
            $fallbackRow = $requestFallback->get_result()->fetch_assoc();
            $requestFallback->close();
            $fallbackServiceId = (int)($fallbackRow['service_id'] ?? 0);
        }

        $allServices = safGetSelectedServices(
            $conn,
            $requestId,
            $fallbackServiceId
        );
        $eligibleServices = safEligibleServices($allServices);

        /* Selected service names are shown exactly as selected in Phase-4. */
        $r['service_labels'] = safServiceLabels($allServices);
        $r['service_names'] = implode(', ', $r['service_labels']);
        $r['eligible_service_count'] = count($eligibleServices);

        /* Count existing SAFs by distinct asset + service combination. */
        $existingCombinations = 0;
        if ($requestId > 0 && $eligibleServices) {
            $existingStmt = $conn->prepare("
                SELECT COUNT(*) AS c
                FROM saf_contracts s
                WHERE s.offer_id = ?
                  AND EXISTS (
                      SELECT 1
                      FROM service_request_assets sra2
                      WHERE sra2.request_id = ?
                        AND sra2.asset_id = s.asset_id
                  )
                  AND (
                      s.service_id IN (
                          SELECT sc.id
                          FROM service_request_services srs
                          INNER JOIN service_catalog sc ON sc.id = srs.service_id
                          WHERE srs.request_id = ?
                            AND UPPER(TRIM(COALESCE(sc.service_code,''))) IN ('PAT','SAT','SCI','DGS')
                      )
                      OR UPPER(TRIM(COALESCE(s.service_code,''))) IN ('PAT','SAT','SCI','DGS')
                  )
            ");
            if ($existingStmt) {
                $existingStmt->bind_param('iii', $r['offer_id'], $requestId, $requestId);
                $existingStmt->execute();
                $existingRow = $existingStmt->get_result()->fetch_assoc();
                $existingStmt->close();
                $existingCombinations = (int)($existingRow['c'] ?? 0);
            }
        }

        $totalAssets = (int)($r['total_assets'] ?? 0);

        /*
         * SAF quantity rules:
         *
         * PAT / SAT / SCI
         *     → one SAF per selected asset + service.
         *
         * DGS / DG
         *     → ONE governance SAF per FSO / PSGA.
         *        It is NOT an inspection SAF and is not duplicated
         *        for every lift/escalator/travellator.
         */
        $technicalServiceCount = 0;
        $hasDgsService = false;

        foreach ($eligibleServices as $eligibleService) {
            $eligibleCode = strtoupper(
                trim((string)($eligibleService['service_code'] ?? ''))
            );

            if (safIsDgsServiceCode($eligibleCode)) {
                $hasDgsService = true;
            } else {
                $technicalServiceCount++;
            }
        }

        $requiredCombinations =
            ($totalAssets * $technicalServiceCount)
            + ($hasDgsService ? 1 : 0);

        /*
         * The existing SAF count query includes DGS/DG rows. Keep
         * the count as a count of actual SAF documents, because
         * DGS is intentionally one document for the whole FSO.
         */
        $r['saf_required'] = $requiredCombinations;
        $r['saf_generated'] = $existingCombinations;
        $r['saf_missing'] = max(
            0,
            $requiredCombinations - $existingCombinations
        );

        if ($requiredCombinations > 0 && $r['saf_missing'] > 0) {
            $confirmedPsgaRows[] = $r;
        }
    }
    $confirmedResult->free();
}

/* =========================================================
   COUNTS
========================================================= */

$total = count($safs);
$pendingReview = 0;
$forwarded = 0;
$accepted = 0;
$assigned = 0;

foreach ($safs as $row) {
    $status = (string)$row['status'];

    if ($status === 'Pending Admin Review') {
        $pendingReview++;
    }

    if ($status === 'Pending Owner Acceptance') {
        $forwarded++;
    }

    if ($status === 'Accepted') {
        $accepted++;

        $sid = (int)$row['id'];

        $selectedCount =
            isset($safAssetsBySaf[$sid])
                ? count($safAssetsBySaf[$sid])
                : 1;

        $assignedCount =
            isset($inspectorAssignmentAssets[$sid])
                ? count($inspectorAssignmentAssets[$sid])
                : 0;

        if ($selectedCount > 0 && $assignedCount >= $selectedCount) {
            $assigned++;
        }
    }
}

/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');
?>

<style>
/* =========================================================
   SAF ADMIN — CLEAN / PROFESSIONAL UI
   ---------------------------------------------------------
   CSS ONLY: No PHP workflow or database functionality changed.
========================================================= */

.saf-page{
    width:100%;
    max-width:1550px;
    margin:0 auto;
    padding:24px 22px 40px;
    box-sizing:border-box;
}

/* ---------- Common cards ---------- */
.saf-header,
.saf-card,
.stat-card{
    background:#fff;
    border:1px solid #e7ebf0;
    border-radius:14px;
    box-shadow:0 3px 12px rgba(15,23,42,.06);
}

.saf-header{
    padding:22px 24px;
    margin-bottom:20px;
}

.saf-header h3{
    margin:0;
    font-size:24px;
    line-height:1.25;
    font-weight:700;
    color:#172033;
}

.saf-header .text-muted{
    font-size:14px;
    line-height:1.55;
}

/* ---------- Workflow strip ---------- */
.psga-flow{
    background:#f7faff;
    border:1px solid #dce8f8;
    border-radius:12px;
    padding:14px 18px;
    margin-bottom:20px!important;
}

.flow-line{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:3px;
    font-size:13px;
    line-height:1.7;
    color:#1e3a5f;
}

.flow-arrow{
    color:#8a98a9;
    padding:0 4px;
    font-weight:500;
}

.psga-flow small{
    display:block;
    margin-top:4px;
    line-height:1.5;
}

/* ---------- Stats ---------- */
.stat-card{
    padding:18px 20px;
    min-height:102px;
    height:100%;
}

.stat-label{
    font-size:11px;
    color:#687586;
    text-transform:uppercase;
    letter-spacing:.45px;
    font-weight:600;
    line-height:1.4;
}

.stat-value{
    font-size:28px;
    line-height:1.15;
    font-weight:750;
    margin-top:8px;
    color:#172033;
}

/* ---------- Cards / generation ---------- */
.saf-card{
    width:100%;
    min-width:0;
    margin-bottom:20px;
    overflow:hidden;
}

/* Main table card must allow the table wrapper to scroll horizontally. */
.saf-main-card{
    overflow:hidden;
}

.generate-card{
    border-left:4px solid #198754;
}

.generate-card > .p-3{
    padding:17px 20px!important;
    background:#fbfefc;
}

.generate-card h5{
    font-size:17px;
    font-weight:700;
    color:#172033;
}

.generate-row{
    background:#fbfffc;
}

/* ---------- Main SAF table ---------- */
 .saf-table-wrap{
    display:block;
    width:100%;
    max-width:100%;
    overflow-x:auto;
    overflow-y:visible;
    -webkit-overflow-scrolling:touch;
    scrollbar-width:thin;
    scrollbar-color:#aeb8c5 #eef1f5;
}

.saf-table-wrap::-webkit-scrollbar{
    height:11px;
}

.saf-table-wrap::-webkit-scrollbar-track{
    background:#eef1f5;
    border-top:1px solid #e1e6ec;
}

.saf-table-wrap::-webkit-scrollbar-thumb{
    background:#aeb8c5;
    border-radius:8px;
    border:2px solid #eef1f5;
}

.saf-table-wrap::-webkit-scrollbar-thumb:hover{
    background:#8995a5;
}

.saf-table{
    width:1690px;
    min-width:1690px;
    margin:0;
    border-collapse:separate;
    border-spacing:0;
    table-layout:fixed;
}

.saf-table th,
.saf-table td{
    box-sizing:border-box;
    min-width:0;
}

.saf-table th{
    padding:13px 14px;
    background:#f7f9fb;
    color:#526174;
    border-bottom:1px solid #dfe5eb;
    font-size:11px;
    line-height:1.35;
    text-transform:uppercase;
    letter-spacing:.45px;
    font-weight:700;
    white-space:nowrap;
    vertical-align:middle;
}

.saf-table td{
    padding:16px 14px;
    border-bottom:1px solid #edf0f3;
    color:#263447;
    font-size:13px;
    line-height:1.5;
    vertical-align:middle;
    overflow-wrap:normal;
    word-break:normal;
}

/* Keep all SAF row information vertically centred against the tallest cell. */
.saf-table tbody td{
    vertical-align:middle !important;
}

.saf-table tbody tr:last-child td{
    border-bottom:0;
}

.saf-table tbody tr:hover td{
    background:#fbfcfe;
}

/* Exact widths for the 9-column SAF table */
.saf-table th:nth-child(1),
.saf-table td:nth-child(1){width:205px;}

.saf-table th:nth-child(2),
.saf-table td:nth-child(2){width:270px;}

.saf-table th:nth-child(3),
.saf-table td:nth-child(3){width:240px;}

.saf-table th:nth-child(4),
.saf-table td:nth-child(4){width:145px;}

.saf-table th:nth-child(5),
.saf-table td:nth-child(5){width:105px;}

.saf-table th:nth-child(6),
.saf-table td:nth-child(6){width:145px;}

.saf-table th:nth-child(7),
.saf-table td:nth-child(7){width:190px;}

.saf-table th:nth-child(8),
.saf-table td:nth-child(8){width:210px;}

.saf-table th:nth-child(9),
.saf-table td:nth-child(9){width:180px;}

/* ---------- Cell typography ---------- */
.owner-name{
    display:block;
    font-size:14px;
    font-weight:700;
    color:#172033;
    margin-bottom:2px;
}

.psga-number{
    display:inline-block;
    font-weight:700;
    color:#0d6efd;
    white-space:nowrap;
}

.asset-line{
    display:block;
    padding:7px 9px;
    margin:0 0 7px;
    background:#f8fafc;
    border:1px solid #e6ebf1;
    border-radius:8px;
    line-height:1.4;
}

.asset-line:last-child{
    margin-bottom:0;
}

.asset-line strong{
    color:#1c2b3f;
    font-size:13px;
}

.asset-inspector-line{
    padding:8px 9px;
    margin:0 0 7px;
    background:#fafbfc;
    border:1px solid #e8edf2;
    border-radius:8px;
}

.asset-inspector-line:last-child{
    margin-bottom:0;
}

.assignment-info{
    margin-top:6px;
    color:#6b7787;
    font-size:11px;
    line-height:1.45;
}

/* ---------- Status badges ---------- */
.status-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    max-width:100%;
    min-height:30px;
    padding:5px 10px;
    border-radius:999px;
    font-size:11px;
    line-height:1.25;
    font-weight:700;
    white-space:normal;
    text-align:center;
}

.status-review{
    background:#fff3cd;
    color:#856404;
}

.status-owner{
    background:#cff4fc;
    color:#055160;
}

.status-accepted{
    background:#d1e7dd;
    color:#0f5132;
}

.status-assigned{
    background:#dbeafe;
    color:#174ea6;
}

.status-other{
    background:#e9ecef;
    color:#495057;
}

/* ---------- Action area ---------- */
.action-wrap,
.saf-table td:last-child .d-flex{
    display:flex;
    flex-direction:column;
    align-items:stretch;
    gap:7px;
    width:100%;
    min-width:0;
}

.action-wrap .btn,
.saf-table td:last-child .btn{
    width:100%;
    min-height:34px;
    padding:7px 10px;
    border-radius:7px;
    font-size:12px;
    font-weight:600;
    white-space:normal;
    line-height:1.3;
}

.action-wrap .badge,
.saf-table td:last-child .badge{
    display:flex;
    width:100%;
    min-height:32px;
    align-items:center;
    justify-content:center;
    box-sizing:border-box;
    white-space:normal;
    line-height:1.3;
    padding:7px 9px;
}

/* Delete button remains visually separate */
.action-wrap form,
.saf-table td:last-child form{
    width:100%;
    margin:0;
}

.action-wrap form .btn,
.saf-table td:last-child form .btn{
    width:100%;
}

/* ---------- Modals ---------- */
.assign-modal-info{
    background:#f7f9fb;
    border:1px solid #e1e6ec;
    border-radius:10px;
    padding:13px 14px;
    margin-bottom:16px;
    line-height:1.5;
}

.modal-content{
    border:0;
    border-radius:14px;
    box-shadow:0 15px 45px rgba(15,23,42,.18);
    overflow:hidden;
}

.modal-header{
    padding:16px 20px;
    border-bottom:1px solid #edf0f3;
}

.modal-body{
    padding:20px;
}

.modal-footer{
    padding:13px 20px;
    border-top:1px solid #edf0f3;
}

/* ---------- Responsive ---------- */
@media(max-width:1200px){
    .saf-page{
        padding:20px 16px 32px;
    }
}

@media(max-width:900px){
    .saf-page{
        padding:14px 10px 28px;
    }

    .saf-header{
        padding:18px;
    }

    .saf-header h3{
        font-size:21px;
    }

    .saf-table{
        min-width:1690px;
    }

    .flow-line{
        font-size:12px;
    }
}
</style>


<div class="content">
<div class="saf-page">

<div class="saf-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h3>
                <i class="bi bi-file-earmark-check me-2"></i>
                SAF - Service Activation Form
            </h3>
            <div class="text-muted mt-1">
                PAT / SAT / SCI / DGS Service Activation Form — review, send to Owner and manage applicable services.
            </div>
        </div>
    </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle-fill me-2"></i>
    <?=h($success)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <?=h($error)?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>



<!-- =====================================================
     CONFIRMED PSGA → SAF GENERATION
====================================================== -->

<div class="saf-card generate-card">
    <div class="p-3 border-bottom">
        <h5 class="mb-1">
            <i class="bi bi-plus-circle me-2"></i>
            Confirmed PSGA — SAF Available
        </h5>
    </div>

    <div class="saf-table-wrap">
    <table class="saf-table saf-generate-table">
        <thead>
        <tr>
            <th>PSGA</th>
            <th>Owner</th>
            <th>FSO</th>
            <th>Request</th>
            <th>Selected Services</th>
            <th>Assets / Locations</th>
            <th>Action</th>
        </tr>
        </thead>

        <tbody>
        <?php if (!$confirmedPsgaRows): ?>
            <tr>
                <td colspan="7" class="text-center text-muted py-4">
                    No Confirmed PSGA is currently waiting for SAF generation.
                </td>
            </tr>
        <?php else: ?>
            <?php foreach ($confirmedPsgaRows as $p): ?>
            <tr class="generate-row">
                <td>
                    <span class="psga-number">
                        <?=h($p['PSGA_No'])?>
                    </span>
                </td>

                <td>
                    <strong><?=h($p['owner_name'] ?: '—')?></strong>
                    <?php if (!empty($p['owner_email'])): ?>
                        <br>
                        <small class="text-muted">
                            <?=h($p['owner_email'])?>
                        </small>
                    <?php endif; ?>
                </td>

                <td>
                    <strong><?=h($p['offer_no'] ?: '—')?></strong>
                </td>

                <td>
                    <?=h($p['request_no'] ?: ('SR #' . (int)$p['request_id']))?>
                </td>

                <td>
                    <?php if (!empty($p['service_labels'])): ?>
                        <?php foreach ($p['service_labels'] as $serviceLabel): ?>
                            <div class="service-name-line">
                                <?=h($serviceLabel)?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <span class="text-muted">Service not configured</span>
                    <?php endif; ?>
                </td>

                <td>
                    <strong><?= (int)$p['saf_generated'] ?> / <?= (int)$p['saf_required'] ?> SAF</strong>
                    <br>
                    <small class="text-muted">
                        <?= (int)$p['saf_missing'] ?> SAF combination<?= (int)$p['saf_missing'] === 1 ? '' : 's' ?> remaining
                    </small>
                </td>

                <td>
                    <form method="POST"
                          onsubmit="return confirm('Generate the SAF for this Confirmed PSGA now?');">
                        <?=csrfField()?>
                        <input type="hidden"
                               name="offer_id"
                               value="<?= (int)$p['offer_id'] ?>">

                        <button type="submit"
                                name="generate_saf"
                                class="btn btn-sm btn-success">
                            <i class="bi bi-file-earmark-plus me-1"></i>
                            Generate SAF
                        </button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- =====================================================
     STATS
====================================================== -->

<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Total SAF</div>
            <div class="stat-value"><?=$total?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Admin Review</div>
            <div class="stat-value text-warning"><?=$pendingReview?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Pending Owner Acceptance</div>
            <div class="stat-value text-info"><?=$forwarded?></div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="stat-card">
            <div class="stat-label">Accepted / Assigned</div>
            <div class="stat-value text-success">
                <?=$accepted?>
                <small class="text-muted" style="font-size:14px;">
                    / <?=$assigned?>
                </small>
            </div>
        </div>
    </div>

</div>

<!-- =====================================================
     SAF TABLE
====================================================== -->

<div class="saf-card saf-main-card">

<div class="saf-table-wrap">
<table class="saf-table">

<thead>
<tr>
    <th>Owner</th>
    <th>Asset</th>
    <th>Service</th>
    <th>PSGA / Contract No.</th>
    <th>SAF No.</th>
    <th>Proposed Inspection</th>
    <th>Status</th>
    <th>Inspector</th>
    <th>Action</th>
</tr>
</thead>

<tbody>

<?php if (!$safs): ?>

<tr>
    <td colspan="9" class="text-center text-muted py-5">
        <i class="bi bi-file-earmark-x" style="font-size:35px;"></i>
        <div class="mt-2">
            No SAF generated yet.
        </div>
    </td>
</tr>

<?php else: ?>

<?php foreach ($safs as $row): ?>

<?php
$safId = (int)$row['id'];
$status = (string)$row['status'];

$selectedAssetCount =
    isset($safAssetsBySaf[$safId])
        ? count($safAssetsBySaf[$safId])
        : 1;

$assignedAssetCount =
    isset($inspectorAssignmentAssets[$safId])
        ? count($inspectorAssignmentAssets[$safId])
        : 0;

$assignmentComplete =
    $selectedAssetCount > 0 &&
    $assignedAssetCount >= $selectedAssetCount;

$statusClass = 'status-other';

if ($status === 'Pending Admin Review') {
    $statusClass = 'status-review';
} elseif ($status === 'Pending Owner Acceptance') {
    $statusClass = 'status-owner';
} elseif ($status === 'Accepted') {
    $statusClass = $assignmentComplete
        ? 'status-assigned'
        : 'status-accepted';
}
?>

<tr>

<!-- OWNER -->
<td>
    <strong class="owner-name">
        <?=h($row['owner_name'] ?: '—')?>
    </strong>

    <?php if (!empty($row['owner_email'])): ?>
        <br>
        <small class="text-muted">
            <?=h($row['owner_email'])?>
        </small>
    <?php endif; ?>
</td>

<!-- ASSET — DGS: ALL PHASE-4 SELECTED ASSETS; PAT/SAT/SCI: THIS SAF'S ASSET -->
<td>
<?php if (!empty($safAssetsBySaf[$safId])): ?>

    <?php foreach ($safAssetsBySaf[$safId] as $asset): ?>
        <div class="asset-line">
            <strong>
                <?=h($asset['display_asset_id'] ?: ('Asset #' . (int)$asset['asset_id']))?>
            </strong>

            <?php if (!empty($asset['asset_name'])): ?>
                — <?=h($asset['asset_name'])?>
            <?php endif; ?>

            <?php if (!empty($asset['location'])): ?>
                <br>
                <small class="text-muted">
                    <?=h($asset['location'])?>
                </small>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    —
<?php endif; ?>
</td>

<!-- SERVICES — ALL SERVICES SELECTED IN PHASE-4, SHOWN ONCE -->
<td>
<?php if (!empty($row['display_service_labels'])): ?>
    <?php foreach ($row['display_service_labels'] as $serviceLabel): ?>
        <div class="service-name-line">
            <?=h($serviceLabel)?>
        </div>
    <?php endforeach; ?>
<?php else: ?>
    <?=h($row['display_service_name'] ?: 'Service not configured')?>
<?php endif; ?>
</td>

<!-- PSGA -->
<td>
    <span class="psga-number">
        <?=h($row['psga_no'] ?: '—')?>
    </span>
</td>

<!-- SAF -->
<td>
    <strong><?=h($row['saf_no'] ?: '—')?></strong>
    <?php if (!empty($row['saf_type'])): ?>
        <br>
        <small class="text-muted">
            <?=h($row['saf_type'])?>
        </small>
    <?php endif; ?>
</td>

<!-- PROPOSED INSPECTION -->
<td>
<?php if (!empty($row['proposed_inspection_date'])): ?>
    <strong>
        <?=h(safDateOnly($row['proposed_inspection_date']))?>
    </strong>
<?php else: ?>
    —
<?php endif; ?>
</td>

<!-- STATUS -->
<td>
    <span class="status-badge <?=$statusClass?>">
        <?=h($status ?: 'Pending Admin Review')?>
    </span>

    <?php if (!empty($row['forwarded_to_owner_at'])): ?>
        <div class="assignment-info">
            Sent: <?=h(safDateTime($row['forwarded_to_owner_at']))?>
        </div>
    <?php endif; ?>

    <?php if (!empty(formatISTDateTime($row['owner_accepted_at']))): ?>
        <div class="assignment-info">
            Owner accepted:
            <?=h(safDateTime(formatISTDateTime($row['owner_accepted_at'])))?>
        </div>
    <?php endif; ?>
</td>

<!-- INSPECTOR — EACH ASSET CAN HAVE A DIFFERENT INSPECTOR -->
<td>
<?php if (!empty($safAssetsBySaf[$safId])): ?>

    <?php foreach ($safAssetsBySaf[$safId] as $asset): ?>

        <?php
        $assetId = (int)$asset['asset_id'];
        $assetAssignment =
            $inspectorAssignmentAssets[$safId][$assetId] ?? null;
        ?>

        <div class="asset-inspector-line">

            <div>
                <strong>
                    <?=h($asset['display_asset_id'] ?: ('Asset #' . $assetId))?>
                </strong>

                <?php if (
                    $assetAssignment &&
                    !empty($assetAssignment['inspector_name'])
                ): ?>

                    <div class="assignment-info">
                        <span class="status-badge status-assigned">
                            <i class="bi bi-person-check me-1"></i>
                            <?=h($assetAssignment['inspector_name'])?>
                        </span>
                    </div>

                <?php else: ?>

                    <div class="assignment-info text-danger fw-semibold">
                        Not Assigned
                    </div>

                <?php endif; ?>
            </div>

            <?php if ($status === 'Accepted' && $assetAssignment): ?>

                <button type="button"
                        class="btn btn-sm btn-outline-warning mt-1"
                        data-bs-toggle="modal"
                        data-bs-target="#reassignSafModal<?=$safId?>_<?=$assetId?>">
                    <i class="bi bi-arrow-repeat me-1"></i>
                    Reassign
                </button>

            <?php endif; ?>

        </div>

    <?php endforeach; ?>

    <div class="assignment-info mt-2">
        <?=$assignedAssetCount?> / <?=$selectedAssetCount?>
        asset inspection<?= $selectedAssetCount === 1 ? '' : 's' ?>
        assigned
    </div>

<?php elseif ($status === 'Accepted'): ?>

    <span class="text-danger fw-semibold">Not Assigned</span>

<?php else: ?>

    <span class="text-muted">—</span>

<?php endif; ?>
</td>

<!-- ACTION -->
<td>

<div class="action-wrap">

<a href="saf-view.php?id=<?=$safId?>"
   class="btn btn-sm btn-outline-primary">
    <i class="bi bi-eye me-1"></i>
    View
</a>

<?php if ($status === 'Pending Admin Review'): ?>

<?php if (safIsDgsServiceCode($row['service_code'] ?? '')): ?>

<form method="POST"
      onsubmit="return confirm('Review and send this DGS SAF to the Owner?');">
    <?=csrfField()?>
    <input type="hidden"
           name="saf_id"
           value="<?=$safId?>">

    <button type="submit"
            name="forward_dgs_saf"
            class="btn btn-sm btn-success">
        <i class="bi bi-send me-1"></i>
        Review &amp; Send
    </button>
</form>

<?php else: ?>

<button type="button"
        class="btn btn-sm btn-success"
        data-bs-toggle="modal"
        data-bs-target="#forwardSafModal<?=$safId?>">
    <i class="bi bi-calendar-check me-1"></i>
    Set Date &amp; Send
</button>

<?php endif; ?>

<?php elseif ($status === 'Pending Owner Acceptance'): ?>

<span class="badge bg-info text-dark">
    <i class="bi bi-send-check me-1"></i>
    Sent to Owner
</span>

<?php elseif ($status === 'Accepted'): ?>

<?php if (safIsDgsServiceCode($row['service_code'] ?? '')): ?>

<?php
$dgsActivationStatus = trim(
    (string)($row['dgs_activation_status'] ?? 'Not Active')
);
$dgsIsActive = ($dgsActivationStatus === 'Active');
?>

<?php if ($dgsIsActive): ?>

<span class="badge bg-success">
    <i class="bi bi-shield-check me-1"></i>
    Governance Service Active
</span>

<?php if (!empty(formatISTDateTime($row['dgs_activated_at']))): ?>
    <small class="text-muted d-block mt-1">
        Activated: <?=h(safDateTime(formatISTDateTime($row['dgs_activated_at'])))?>
    </small>
<?php endif; ?>

<?php else: ?>

<form method="POST"
      action="activate-dgs.php"
      onsubmit="return confirm('Activate Governance Services for all assets selected for this DGS service request?');">
    <?=csrfField()?>
    <input type="hidden" name="saf_id" value="<?=$safId?>">

    <button type="submit"
            class="btn btn-sm btn-success">
        <i class="bi bi-shield-check me-1"></i>
        Activate Governance Services
    </button>
</form>

<small class="text-warning d-block mt-1">
    <i class="bi bi-clock me-1"></i>
    Waiting for Admin activation
</small>

<?php endif; ?>

<?php else: ?>

<button type="button"
        class="btn btn-sm <?=$assignmentComplete ? 'btn-outline-primary' : 'btn-success'?>"
        data-bs-toggle="modal"
        data-bs-target="#assignSafModal<?=$safId?>">
    <i class="bi bi-people me-1"></i>
    Manage Inspectors
</button>

<?php endif; ?>

<?php endif; ?>

<form method="POST"
      class="d-inline"
      onsubmit="return confirm(
        'WARNING: This will permanently delete this SAF, its SAF inspection jobs, Inspector assignments and report requests. The permanent PSGA will NOT be deleted. Continue?'
      );">
    <?=csrfField()?>
    <input type="hidden"
           name="saf_id"
           value="<?=$safId?>">

    <button type="submit"
            name="delete_saf"
            class="btn btn-sm btn-outline-danger">
        <i class="bi bi-trash3 me-1"></i>
        Delete SAF
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

<!-- =====================================================
     SET DATE + SEND MODALS
====================================================== -->

<?php foreach ($safs as $row): ?>
<?php if ($row['status'] !== 'Pending Admin Review') continue; ?>
<?php if (safIsDgsServiceCode($row['service_code'] ?? '')) continue; ?>

<div class="modal fade"
     id="forwardSafModal<?= (int)$row['id'] ?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="POST"
                  onsubmit="return confirm(
                    'Set the Proposed Inspection Date and send this SAF to the Owner?'
                  );">

                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-calendar-check me-2"></i>
                        Proposed Inspection Date
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <?=csrfField()?>

                    <input type="hidden"
                           name="saf_id"
                           value="<?= (int)$row['id'] ?>">

                    <div class="alert alert-light border">
                        <strong>
                            SAF <?=h($row['saf_no'])?>
                        </strong>

                        <div class="small text-muted mt-1">
                            PSGA:
                            <?=h($row['psga_no'])?>
                        </div>

                        <div class="small text-muted">
                            Owner:
                            <?=h($row['owner_name'] ?: 'Unknown')?>
                        </div>
                    </div>

                    <label class="form-label fw-semibold">
                        Proposed Inspection Date
                        <span class="text-danger">*</span>
                    </label>

                    <input type="date"
                           name="proposed_inspection_date"
                           class="form-control"
                           min="<?=date('Y-m-d')?>"
                           value="<?=!empty($row['proposed_inspection_date'])
                                ? h($row['proposed_inspection_date'])
                                : date('Y-m-d')?>"
                           required>

                    <div class="small text-muted mt-2">
                        This date is stored on the SAF and is not changed by
                        Owner OTP acceptance.
                    </div>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <button type="submit"
                            name="forward_saf"
                            class="btn btn-success">
                        <i class="bi bi-send me-1"></i>
                        Set Date &amp; Send to Owner
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endforeach; ?>

<!-- =====================================================
     ASSIGN INSPECTORS — ONE INSPECTOR PER ASSET
====================================================== -->

<?php foreach ($safs as $row): ?>

<?php
if ($row['status'] !== 'Accepted') continue;

$safId = (int)$row['id'];

$selectedCount =
    isset($safAssetsBySaf[$safId])
        ? count($safAssetsBySaf[$safId])
        : 0;

$assignedCount =
    isset($inspectorAssignmentAssets[$safId])
        ? count($inspectorAssignmentAssets[$safId])
        : 0;
?>

<div class="modal fade"
     id="assignSafModal<?=$safId?>"
     tabindex="-1"
     aria-hidden="true">

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content">

            <form method="POST"
                  onsubmit="return validateAssetInspectorForm(<?=$safId?>);">

                <div class="modal-header">

                    <div>
                        <h5 class="modal-title mb-1">
                            <i class="bi bi-people me-2"></i>
                            Manage Inspectors — SAF <?=h($row['saf_no'])?>
                        </h5>

                        <small class="text-muted">
                            Each asset can have a different Inspector.
                            <?=$assignedCount?> / <?=$selectedCount?> currently assigned.
                        </small>
                    </div>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>

                </div>

                <div class="modal-body">

                    <?=csrfField()?>

                    <input type="hidden"
                           name="saf_id"
                           value="<?=$safId?>">

                    <div class="alert alert-primary">
                        <strong>Asset-wise Inspector Assignment</strong>
                    </div>

                    <?php if (!$inspectors): ?>

                        <div class="alert alert-warning mb-0">
                            No Inspector accounts are available.
                        </div>

                    <?php elseif (empty($safAssetsBySaf[$safId])): ?>

                        <div class="alert alert-danger mb-0">
                            No assets were found for this SAF.
                        </div>

                    <?php else: ?>

                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">

                                <thead class="table-light">
                                <tr>
                                    <th style="width:32%;">Asset</th>
                                    <th style="width:25%;">Location</th>
                                    <th style="width:43%;">Assign Inspector</th>
                                </tr>
                                </thead>

                                <tbody>

                                <?php foreach ($safAssetsBySaf[$safId] as $asset): ?>

                                    <?php
                                    $assetId = (int)$asset['asset_id'];

                                    $currentAssignment =
                                        $inspectorAssignmentAssets[$safId][$assetId]
                                        ?? null;

                                    $currentInspectorId =
                                        (int)($currentAssignment['inspector_id'] ?? 0);
                                    ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?=h(
                                                    $asset['display_asset_id']
                                                    ?: ('Asset #' . $assetId)
                                                )?>
                                            </strong>

                                            <?php if (!empty($asset['asset_name'])): ?>
                                                <div class="small text-muted">
                                                    <?=h($asset['asset_name'])?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($currentAssignment): ?>
                                                <span class="badge bg-success mt-1">
                                                    <i class="bi bi-person-check me-1"></i>
                                                    Currently Assigned
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark mt-1">
                                                    Not Assigned
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <?=h($asset['location'] ?: '—')?>
                                        </td>

                                        <td>
                                            <select
                                                name="asset_inspector[<?=$assetId?>]"
                                                class="form-select asset-inspector-select"
                                                data-saf-id="<?=$safId?>"
                                                data-asset-id="<?=$assetId?>"
                                            >

                                                <option value="">
                                                    <?= $currentAssignment
                                                        ? 'Keep current Inspector'
                                                        : 'Select Inspector' ?>
                                                </option>

                                                <?php foreach ($inspectors as $inspector): ?>

                                                    <option
                                                        value="<?= (int)$inspector['id'] ?>"
                                                        <?=$currentInspectorId === (int)$inspector['id']
                                                            ? 'selected'
                                                            : ''?>
                                                    >
                                                        <?=h($inspector['name'])?>

                                                        <?php if (!empty($inspector['email'])): ?>
                                                            — <?=h($inspector['email'])?>
                                                        <?php endif; ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                            <?php if ($currentAssignment): ?>
                                                <small class="text-muted">
                                                    Current:
                                                    <strong>
                                                        <?=h($currentAssignment['inspector_name'] ?? 'Inspector')?>
                                                    </strong>
                                                </small>
                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>
                        </div>

                        <div class="alert alert-light border mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            Existing assignment for an asset will be updated when
                            you select another Inspector. No duplicate active
                            assignment is created for the same asset.
                        </div>

                    <?php endif; ?>

                </div>

                <div class="modal-footer">

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>

                    <?php if ($inspectors && !empty($safAssetsBySaf[$safId])): ?>

                        <button type="submit"
                                name="assign_saf_inspectors"
                                class="btn btn-success">
                            <i class="bi bi-save me-1"></i>
                            Save Asset-wise Assignments
                        </button>

                    <?php endif; ?>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endforeach; ?>


<!-- =====================================================
     REASSIGN INSPECTOR — PARTICULAR ASSET
====================================================== -->

<?php foreach ($safs as $row): ?>

<?php
if ($row['status'] !== 'Accepted') continue;

$safId = (int)$row['id'];

if (empty($safAssetsBySaf[$safId])) continue;
?>

    <?php foreach ($safAssetsBySaf[$safId] as $asset): ?>

        <?php
        $assetId = (int)$asset['asset_id'];

        $currentAssignment =
            $inspectorAssignmentAssets[$safId][$assetId]
            ?? null;

        if (!$currentAssignment) continue;

        $currentInspectorId =
            (int)($currentAssignment['inspector_id'] ?? 0);
        ?>

        <div class="modal fade"
             id="reassignSafModal<?=$safId?>_<?=$assetId?>"
             tabindex="-1"
             aria-hidden="true">

            <div class="modal-dialog modal-dialog-centered">

                <div class="modal-content">

                    <form method="POST"
                          onsubmit="return confirm(
                              'Reassign this particular asset to the selected Inspector?'
                          );">

                        <div class="modal-header">

                            <h5 class="modal-title">
                                <i class="bi bi-arrow-repeat me-2"></i>
                                Reassign Inspector
                            </h5>

                            <button type="button"
                                    class="btn-close"
                                    data-bs-dismiss="modal"></button>

                        </div>

                        <div class="modal-body">

                            <?=csrfField()?>

                            <input type="hidden"
                                   name="saf_id"
                                   value="<?=$safId?>">

                            <input type="hidden"
                                   name="asset_id"
                                   value="<?=$assetId?>">

                            <div class="alert alert-light border">

                                <div>
                                    <strong>Asset:</strong>
                                    <?=h(
                                        $asset['display_asset_id']
                                        ?: ('Asset #' . $assetId)
                                    )?>
                                </div>

                                <?php if (!empty($asset['asset_name'])): ?>
                                    <div class="small text-muted">
                                        <?=h($asset['asset_name'])?>
                                    </div>
                                <?php endif; ?>

                                <div class="small text-muted mt-1">
                                    <strong>Location:</strong>
                                    <?=h($asset['location'] ?: '—')?>
                                </div>

                                <hr>

                                <div>
                                    <strong>Current Inspector:</strong>
                                    <?=h(
                                        $currentAssignment['inspector_name']
                                        ?: 'Unknown'
                                    )?>
                                </div>

                            </div>

                            <label class="form-label fw-semibold">
                                New Inspector
                                <span class="text-danger">*</span>
                            </label>

                            <select name="inspector_id"
                                    class="form-select"
                                    required>

                                <option value="">
                                    Select New Inspector
                                </option>

                                <?php foreach ($inspectors as $inspector): ?>

                                    <option
                                        value="<?= (int)$inspector['id'] ?>"
                                        <?=$currentInspectorId === (int)$inspector['id']
                                            ? 'disabled'
                                            : ''?>
                                    >
                                        <?=h($inspector['name'])?>

                                        <?php if (!empty($inspector['email'])): ?>
                                            — <?=h($inspector['email'])?>
                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                            <div class="small text-muted mt-2">
                                Reassignment is only allowed before this asset's
                                inspection has started. Existing inspection data
                                is protected after work starts.
                            </div>

                        </div>

                        <div class="modal-footer">

                            <button type="button"
                                    class="btn btn-secondary"
                                    data-bs-dismiss="modal">
                                Cancel
                            </button>

                            <?php if (count($inspectors) > 1): ?>

                                <button type="submit"
                                        name="reassign_saf_inspector"
                                        class="btn btn-warning">
                                    <i class="bi bi-arrow-repeat me-1"></i>
                                    Reassign Inspector
                                </button>

                            <?php endif; ?>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

<?php endforeach; ?>


<script>
function validateAssetInspectorForm(safId) {
    const modal = document.getElementById('assignSafModal' + safId);

    if (!modal) {
        return true;
    }

    const selects =
        modal.querySelectorAll('.asset-inspector-select');

    let selectedSomething = false;

    selects.forEach(function(select) {
        if (select.value !== '') {
            selectedSomething = true;
        }
    });

    if (!selectedSomething) {
        alert(
            'Please select an Inspector for at least one asset.'
        );
        return false;
    }

    return confirm(
        'Save asset-wise Inspector assignments? Each asset will keep its selected Inspector.'
    );
}
</script>

<?php include('../includes/footer.php'); ?>

<style>
/* Generation table has fewer columns, so keep it compact while still scrollable. */
.saf-generate-table{
    width:1260px;
    min-width:1260px;
}
.saf-generate-table th:nth-child(1), .saf-generate-table td:nth-child(1){width:150px;}
.saf-generate-table th:nth-child(2), .saf-generate-table td:nth-child(2){width:230px;}
.saf-generate-table th:nth-child(3), .saf-generate-table td:nth-child(3){width:170px;}
.saf-generate-table th:nth-child(4), .saf-generate-table td:nth-child(4){width:210px;}
.saf-generate-table th:nth-child(5), .saf-generate-table td:nth-child(5){width:190px;}
.saf-generate-table th:nth-child(6), .saf-generate-table td:nth-child(6){width:150px;}
.saf-generate-table th:nth-child(7), .saf-generate-table td:nth-child(7){width:160px;}

/* Do not let long words or buttons push information outside the scroll area. */
.saf-table td, .saf-table th{
    overflow:hidden;
}
.saf-table td:last-child, .saf-table td:nth-child(8){
    overflow:visible;
}
.saf-table .owner-name,
.saf-table .psga-number,
.saf-table td strong,
.saf-table td small{
    overflow-wrap:anywhere;
}

.service-name-line{
    display:block;
    padding:6px 8px;
    margin:0 0 6px;
    background:#f8fafc;
    border:1px solid #e5ebf2;
    border-radius:7px;
    color:#174ea6;
    font-weight:600;
    font-size:12px;
    line-height:1.4;
    overflow-wrap:anywhere;
}
.service-name-line:last-child{margin-bottom:0;}
</style>
