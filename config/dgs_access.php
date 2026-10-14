<?php
/* =========================================================
   NLETA — DGS GOVERNANCE ACCESS HELPER
   ---------------------------------------------------------
   DGS / DG is handled separately from PAT / SAT / SCI.

   Rules:
   - SAF must be Owner Accepted.
   - Admin must explicitly activate Governance Services.
   - Access is asset-wise through dgs_asset_access.
   - Only assets selected for the DGS Phase-4 request are active.
   - No Inspector / maintenance-job logic is used here.
========================================================= */

if (!function_exists('dgsTableExists')) {
    function dgsTableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare(""
            . "SELECT COUNT(*) AS c\n"
            . "FROM INFORMATION_SCHEMA.TABLES\n"
            . "WHERE TABLE_SCHEMA = DATABASE()\n"
            . "  AND TABLE_NAME = ?\n"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['c'] ?? 0) > 0;
    }
}

if (!function_exists('isDgsServiceCode')) {
    function isDgsServiceCode(string $code): bool
    {
        $code = strtoupper(trim($code));

        return in_array($code, [
            'DG',
            'DGS',
            'DIGITAL_GOVERNANCE'
        ], true);
    }
}

if (!function_exists('dgsSafIsValid')) {
    function dgsSafIsValid(mysqli $conn, int $safId, int $ownerId = 0): bool
    {
        if ($safId <= 0) {
            return false;
        }

        $sql = ""
            . "SELECT s.id, s.owner_id, s.status, s.service_code, s.service_name,\n"
            . "       s.dgs_activation_status\n"
            . "FROM saf_contracts s\n"
            . "WHERE s.id = ?\n"
            . "  AND s.status = 'Accepted'\n"
            . "  AND s.dgs_activation_status = 'Active'\n";

        if ($ownerId > 0) {
            $sql .= " AND s.owner_id = ?\n";
        }

        $sql .= " LIMIT 1";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        if ($ownerId > 0) {
            $stmt->bind_param('ii', $safId, $ownerId);
        } else {
            $stmt->bind_param('i', $safId);
        }

        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return false;
        }

        return isDgsServiceCode((string)($row['service_code'] ?? ''))
            || stripos((string)($row['service_name'] ?? ''), 'DIGITAL GOVERNANCE') !== false;
    }
}

if (!function_exists('ownerHasDgsAssetAccess')) {
    function ownerHasDgsAssetAccess(mysqli $conn, int $ownerId, int $assetId): bool
    {
        if ($ownerId <= 0 || $assetId <= 0 || !dgsTableExists($conn, 'dgs_asset_access')) {
            return false;
        }

        $stmt = $conn->prepare(""
            . "SELECT daa.id\n"
            . "FROM dgs_asset_access daa\n"
            . "INNER JOIN saf_contracts s ON s.id = daa.saf_id\n"
            . "WHERE daa.owner_id = ?\n"
            . "  AND daa.asset_id = ?\n"
            . "  AND daa.is_active = 1\n"
            . "  AND s.owner_id = daa.owner_id\n"
            . "  AND s.status = 'Accepted'\n"
            . "  AND s.dgs_activation_status = 'Active'\n"
            . "  AND (\n"
            . "      UPPER(TRIM(COALESCE(s.service_code,''))) IN ('DG','DGS','DIGITAL_GOVERNANCE')\n"
            . "      OR UPPER(COALESCE(s.service_name,'')) LIKE '%DIGITAL GOVERNANCE%'\n"
            . "  )\n"
            . "LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $ownerId, $assetId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool)$row;
    }
}

if (!function_exists('getOwnerDgsAssetIds')) {
    function getOwnerDgsAssetIds(mysqli $conn, int $ownerId): array
    {
        if ($ownerId <= 0 || !dgsTableExists($conn, 'dgs_asset_access')) {
            return [];
        }

        $stmt = $conn->prepare(""
            . "SELECT DISTINCT daa.asset_id\n"
            . "FROM dgs_asset_access daa\n"
            . "INNER JOIN saf_contracts s ON s.id = daa.saf_id\n"
            . "WHERE daa.owner_id = ?\n"
            . "  AND daa.is_active = 1\n"
            . "  AND s.owner_id = daa.owner_id\n"
            . "  AND s.status = 'Accepted'\n"
            . "  AND s.dgs_activation_status = 'Active'\n"
            . "  AND (\n"
            . "      UPPER(TRIM(COALESCE(s.service_code,''))) IN ('DG','DGS','DIGITAL_GOVERNANCE')\n"
            . "      OR UPPER(COALESCE(s.service_name,'')) LIKE '%DIGITAL GOVERNANCE%'\n"
            . "  )\n"
            . "ORDER BY daa.asset_id ASC"
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();

        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $id = (int)($row['asset_id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $stmt->close();

        return array_values(array_unique($ids));
    }
}

if (!function_exists('getOwnerDgsAssets')) {
    function getOwnerDgsAssets(mysqli $conn, int $ownerId): array
    {
        $assetIds = getOwnerDgsAssetIds($conn, $ownerId);
        if (!$assetIds || !dgsTableExists($conn, 'assets')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($assetIds), '?'));
        $types = str_repeat('i', count($assetIds));

        $sql = "SELECT id, display_asset_id, asset_name, location\n"
             . "FROM assets\n"
             . "WHERE id IN ($placeholders)\n"
             . "ORDER BY id ASC";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }

        $params = [$types];
        foreach ($assetIds as $k => $id) {
            $params[] = $id;
        }

        $refs = [];
        foreach ($params as $k => $v) {
            $refs[$k] = &$params[$k];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);
        $stmt->execute();
        $result = $stmt->get_result();

        $assets = [];
        while ($row = $result->fetch_assoc()) {
            $assets[] = $row;
        }

        $stmt->close();
        return $assets;
    }
}

if (!function_exists('getOwnerDgsSafs')) {
    function getOwnerDgsSafs(mysqli $conn, int $ownerId): array
    {
        if ($ownerId <= 0) {
            return [];
        }

        $stmt = $conn->prepare(""
            . "SELECT DISTINCT\n"
            . "    s.id, s.saf_no, s.psga_no, s.request_id, s.owner_id,\n"
            . "    s.service_id, s.service_code, s.service_name,\n"
            . "    s.status, s.dgs_activation_status, s.dgs_activated_at,\n"
            . "    s.generated_at, s.owner_accepted_at\n"
            . "FROM saf_contracts s\n"
            . "INNER JOIN dgs_asset_access daa ON daa.saf_id = s.id\n"
            . "WHERE s.owner_id = ?\n"
            . "  AND daa.owner_id = ?\n"
            . "  AND daa.is_active = 1\n"
            . "  AND s.status = 'Accepted'\n"
            . "  AND s.dgs_activation_status = 'Active'\n"
            . "  AND (\n"
            . "      UPPER(TRIM(COALESCE(s.service_code,''))) IN ('DG','DGS','DIGITAL_GOVERNANCE')\n"
            . "      OR UPPER(COALESCE(s.service_name,'')) LIKE '%DIGITAL GOVERNANCE%'\n"
            . "  )\n"
            . "ORDER BY s.id DESC"
        );

        if (!$stmt) {
            return [];
        }

        $stmt->bind_param('ii', $ownerId, $ownerId);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();
        return $rows;
    }
}

if (!function_exists('ownerHasActiveDgs')) {
    function ownerHasActiveDgs(mysqli $conn, int $ownerId): bool
    {
        if ($ownerId <= 0 || !dgsTableExists($conn, 'dgs_asset_access')) {
            return false;
        }

        $stmt = $conn->prepare(""
            . "SELECT 1\n"
            . "FROM dgs_asset_access daa\n"
            . "INNER JOIN saf_contracts s ON s.id = daa.saf_id\n"
            . "WHERE daa.owner_id = ?\n"
            . "  AND daa.is_active = 1\n"
            . "  AND s.owner_id = daa.owner_id\n"
            . "  AND s.status = 'Accepted'\n"
            . "  AND s.dgs_activation_status = 'Active'\n"
            . "  AND (\n"
            . "      UPPER(TRIM(COALESCE(s.service_code,''))) IN ('DG','DGS','DIGITAL_GOVERNANCE')\n"
            . "      OR UPPER(COALESCE(s.service_name,'')) LIKE '%DIGITAL GOVERNANCE%'\n"
            . "  )\n"
            . "LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $ownerId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool)$row;
    }
}
