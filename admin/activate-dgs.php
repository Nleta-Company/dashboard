<?php
/* =========================================================
   NLETA ADMIN — ACTIVATE DGS GOVERNANCE SERVICES
   ---------------------------------------------------------
   Workflow:
       DGS SAF generated
           ↓
       Owner accepts SAF
           ↓
       Admin clicks Activate Governance Services
           ↓
       Exact Phase-4 selected assets are activated
           ↓
       Owner Governance Services access is unlocked

   IMPORTANT:
   - saf_contracts.status remains 'Accepted'.
   - dgs_activation_status becomes 'Active'.
   - No Inspector / maintenance job is created.
   - DGS access is asset-wise.
========================================================= */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/dgs_access.php';

require_role('admin');

date_default_timezone_set('Asia/Kolkata');

function dgsActivationRedirect(int $safId, string $type, string $message): void
{
    $base = 'saf-view.php?id=' . $safId;
    $url  = $base . '&' . $type . '=' . urlencode($message);
    header('Location: ' . $url);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $safId = (int)($_GET['saf_id'] ?? $_GET['id'] ?? 0);

    if ($safId > 0) {
        dgsActivationRedirect(
            $safId,
            'error',
            'Governance Services must be activated using the Admin action button.'
        );
    }

    header('Location: saf-contracts.php');
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    dgsActivationRedirect(
        (int)($_POST['saf_id'] ?? 0),
        'error',
        'Invalid request. Please refresh the page and try again.'
    );
}

$safId = (int)($_POST['saf_id'] ?? 0);
$adminId = (int)($_SESSION['uid'] ?? $_SESSION['user_id'] ?? 0);

if ($safId <= 0) {
    header('Location: saf-contracts.php');
    exit;
}

try {
    if (!dgsTableExists($conn, 'dgs_asset_access')) {
        throw new Exception(
            'DGS access table is missing. Please create dgs_asset_access first.'
        );
    }

    /* -----------------------------------------------------
       Load and validate the DGS SAF.
    ----------------------------------------------------- */
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.request_id,
            s.owner_id,
            s.asset_id,
            s.psga_no,
            s.saf_no,
            s.service_id,
            s.service_code,
            s.service_name,
            s.status,
            s.dgs_activation_status
        FROM saf_contracts s
        WHERE s.id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        throw new Exception('Unable to load DGS SAF: ' . $conn->error);
    }

    $stmt->bind_param('i', $safId);
    $stmt->execute();
    $saf = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$saf) {
        throw new Exception('DGS SAF not found.');
    }

    $serviceCode = strtoupper(trim((string)($saf['service_code'] ?? '')));
    $serviceName = trim((string)($saf['service_name'] ?? ''));

    $isDgs = isDgsServiceCode($serviceCode)
        || stripos($serviceName, 'DIGITAL GOVERNANCE') !== false
        || stripos($serviceName, 'GOVERNANCE SUPPORT') !== false;

    if (!$isDgs) {
        throw new Exception(
            'This SAF is not a Digital Governance Support SAF.'
        );
    }

    if ((string)$saf['status'] !== 'Accepted') {
        throw new Exception(
            'Governance Services can be activated only after Owner acceptance of the DGS SAF.'
        );
    }

    $requestId = (int)($saf['request_id'] ?? 0);
    $ownerId   = (int)($saf['owner_id'] ?? 0);

    if ($requestId <= 0) {
        throw new Exception(
            'The DGS SAF is not linked to a valid Phase-4 Service Request.'
        );
    }

    if ($ownerId <= 0) {
        throw new Exception('The DGS SAF has no valid Owner.');
    }

    /* -----------------------------------------------------
       Load EXACT assets selected in Phase-4 for this request.
       service_request_assets is the authoritative Phase-4
       asset relation used by the existing SAF generator.
    ----------------------------------------------------- */
    if (!dgsTableExists($conn, 'service_request_assets')) {
        throw new Exception('service_request_assets table was not found.');
    }

    if (!dgsTableExists($conn, 'assets')) {
        throw new Exception('assets table was not found.');
    }

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
            'Unable to load DGS selected assets: ' . $conn->error
        );
    }

    $assetStmt->bind_param('i', $requestId);
    $assetStmt->execute();
    $assetResult = $assetStmt->get_result();

    $selectedAssets = [];

    while ($asset = $assetResult->fetch_assoc()) {
        $assetId = (int)($asset['asset_id'] ?? 0);

        if ($assetId > 0) {
            $selectedAssets[$assetId] = $asset;
        }
    }

    $assetStmt->close();

    if (!$selectedAssets) {
        throw new Exception(
            'No assets are selected in Phase-4 for this DGS Service Request.'
        );
    }

    /* -----------------------------------------------------
       Transaction + row lock prevents double activation.
    ----------------------------------------------------- */
    $conn->begin_transaction();

    try {
        $lockStmt = $conn->prepare("
            SELECT
                id,
                request_id,
                owner_id,
                status,
                service_code,
                service_name,
                dgs_activation_status
            FROM saf_contracts
            WHERE id = ?
            FOR UPDATE
        ");

        if (!$lockStmt) {
            throw new Exception(
                'Unable to lock DGS SAF: ' . $conn->error
            );
        }

        $lockStmt->bind_param('i', $safId);
        $lockStmt->execute();
        $lockedSaf = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$lockedSaf) {
            throw new Exception('DGS SAF could not be locked.');
        }

        if ((int)$lockedSaf['owner_id'] !== $ownerId) {
            throw new Exception('DGS SAF Owner validation failed.');
        }

        if ((int)$lockedSaf['request_id'] !== $requestId) {
            throw new Exception('DGS Service Request validation failed.');
        }

        if ((string)$lockedSaf['status'] !== 'Accepted') {
            throw new Exception(
                'DGS SAF is no longer in Accepted status.'
            );
        }

        $lockedCode = strtoupper(
            trim((string)($lockedSaf['service_code'] ?? ''))
        );
        $lockedName = trim((string)($lockedSaf['service_name'] ?? ''));

        if (
            !isDgsServiceCode($lockedCode) &&
            stripos($lockedName, 'DIGITAL GOVERNANCE') === false &&
            stripos($lockedName, 'GOVERNANCE SUPPORT') === false
        ) {
            throw new Exception(
                'The locked SAF is not a Digital Governance Support SAF.'
            );
        }

        /* -------------------------------------------------
           Insert / reactivate ONLY the selected assets.
           The UNIQUE key is (saf_id, asset_id), so repeated
           activation cannot create duplicate rows.
        ------------------------------------------------- */
        $accessStmt = $conn->prepare("
            INSERT INTO dgs_asset_access
            (
                saf_id,
                owner_id,
                asset_id,
                is_active,
                activated_by,
                activated_at,
                created_at,
                updated_at
            )
            VALUES
            (
                ?,
                ?,
                ?,
                1,
                ?,
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                owner_id = VALUES(owner_id),
                is_active = 1,
                activated_by = VALUES(activated_by),
                activated_at = VALUES(activated_at),
                updated_at = NOW()
        ");

        if (!$accessStmt) {
            throw new Exception(
                'Unable to prepare DGS asset access: ' . $conn->error
            );
        }

        $activatedCount = 0;

        foreach ($selectedAssets as $assetId => $asset) {
            $accessStmt->bind_param(
                'iiii',
                $safId,
                $ownerId,
                $assetId,
                $adminId
            );

            if (!$accessStmt->execute()) {
                throw new Exception(
                    'Unable to activate Governance Services for asset ' .
                    ($asset['display_asset_id'] ?: ('#' . $assetId)) .
                    ': ' .
                    $accessStmt->error
                );
            }

            $activatedCount++;
        }

        $accessStmt->close();

        if ($activatedCount <= 0) {
            throw new Exception(
                'No DGS assets were activated.'
            );
        }

        /* -------------------------------------------------
           IMPORTANT:
           Keep saf_contracts.status = Accepted.
           DGS activation has its own separate state.
        ------------------------------------------------- */
        $updateSaf = $conn->prepare("
            UPDATE saf_contracts
            SET
                dgs_activation_status = 'Active',
                dgs_activated_by = ?,
                dgs_activated_at = NOW(),
                updated_at = NOW()
            WHERE id = ?
              AND status = 'Accepted'
        ");

        if (!$updateSaf) {
            throw new Exception(
                'Unable to update DGS activation status: ' . $conn->error
            );
        }

        $updateSaf->bind_param('ii', $adminId, $safId);

        if (!$updateSaf->execute() || $updateSaf->affected_rows < 1) {
            throw new Exception(
                'DGS activation status could not be updated.'
            );
        }

        $updateSaf->close();

        $conn->commit();

        $success =
            'Governance Services activated successfully for ' .
            $activatedCount .
            ' selected asset' .
            ($activatedCount === 1 ? '' : 's') .
            '.';

        dgsActivationRedirect($safId, 'success', $success);

    } catch (Throwable $inner) {
        $conn->rollback();
        throw $inner;
    }

} catch (Throwable $e) {
    dgsActivationRedirect($safId, 'error', $e->getMessage());
}
