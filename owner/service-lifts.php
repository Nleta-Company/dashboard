
<?php
/* =========================================================
   NLETA — PHASE 4 — STEP 2
   OWNER: ADD / SELECT ASSETS
========================================================= */

include('../config/db.php');
include('../config/auth.php');
require_role('owner');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$owner_id = (int)($_SESSION['uid'] ?? 0);

if ($owner_id <= 0) {
    header("Location: ../login.php");
    exit;
}

date_default_timezone_set('Asia/Kolkata');

/* =========================================================
   CSRF
========================================================= */

if (empty($_SESSION['csrf_service_lifts'])) {
    $_SESSION['csrf_service_lifts'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_service_lifts'];

/* =========================================================
   SERVICES FROM STEP 1
   ---------------------------------------------------------
   Step 1 now supports ONE OR MULTIPLE services.

   New session:
       phase4_service_ids
       phase4_service_codes
       phase4_service_names

   Old single-service session is supported only as a
   backward-compatible fallback.
========================================================= */

$service_ids = [];

if (
    isset($_SESSION['phase4_service_ids'])
    &&
    is_array($_SESSION['phase4_service_ids'])
) {

    foreach (
        $_SESSION['phase4_service_ids'] as $id
    ) {

        $id = (int)$id;

        if ($id > 0) {
            $service_ids[] = $id;
        }
    }
}

/*
 * Backward compatibility with the previous single-service
 * implementation.
 */
if (
    empty($service_ids)
    &&
    !empty($_SESSION['phase4_service_id'])
) {

    $oldServiceId =
        (int)$_SESSION['phase4_service_id'];

    if ($oldServiceId > 0) {
        $service_ids[] = $oldServiceId;
    }
}

$service_ids = array_values(
    array_unique($service_ids)
);


if (empty($service_ids)) {

    header("Location: service-selection.php");
    exit;
}


/* =========================================================
   LOAD ALL SELECTED SERVICES
========================================================= */

$services = [];

$placeholders = implode(
    ',',
    array_fill(
        0,
        count($service_ids),
        '?'
    )
);

$types = str_repeat(
    'i',
    count($service_ids)
);

$serviceStmt = $conn->prepare("
    SELECT
        id,
        service_code,
        service_name,
        description
    FROM service_catalog
    WHERE id IN ($placeholders)
      AND is_active = 1
    ORDER BY
        sort_order ASC,
        id ASC
");

if (!$serviceStmt) {

    unset(
        $_SESSION['phase4_service_ids'],
        $_SESSION['phase4_service_codes'],
        $_SESSION['phase4_service_names']
    );

    header("Location: service-selection.php");
    exit;
}


$bindValues = $service_ids;

$refs = [$types];

foreach (
    $bindValues as $key => $value
) {
    $refs[] = &$bindValues[$key];
}

call_user_func_array(
    [$serviceStmt, 'bind_param'],
    $refs
);

$serviceStmt->execute();

$serviceResult =
    $serviceStmt->get_result();

while (
    $row =
    $serviceResult->fetch_assoc()
) {

    $services[] = $row;
}

$serviceStmt->close();


/* =========================================================
   VALIDATE ALL SERVICES
========================================================= */

if (
    count($services) !==
    count($service_ids)
) {

    unset(
        $_SESSION['phase4_service_ids'],
        $_SESSION['phase4_service_codes'],
        $_SESSION['phase4_service_names'],
        $_SESSION['phase4_service_id'],
        $_SESSION['phase4_service_code'],
        $_SESSION['phase4_service_name']
    );

    header("Location: service-selection.php");
    exit;
}


/* =========================================================
   SERVICE FLAGS
========================================================= */

$serviceCodes = [];

$isPAT = false;
$isSAT = false;
$isSCI = false;

foreach (
    $services as $selectedService
) {

    $code =
        strtoupper(
            trim(
                (string)$selectedService['service_code']
            )
        );

    $serviceCodes[] = $code;

    if ($code === 'PAT') {
        $isPAT = true;
    }

    if ($code === 'SAT') {
        $isSAT = true;
    }

    if (
        $code === 'SCI'
        ||
        $code === 'PAT_SAT'
        ||
        str_contains(
            strtolower(
                trim(
                    (string)$selectedService['service_name']
                )
            ),
            'integrated engineering governance support'
        )
    ) {
        $isSCI = true;
    }
}

/*
 * =========================================================
 * CURRENT REQUEST DEPENDENCY FLAGS
 * =========================================================
 *
 * A prerequisite selected in THIS request satisfies that
 * dependency. Historical completion is only required when
 * the prerequisite service was NOT selected in this request.
 *
 * Examples:
 *   PAT + SAT       -> allowed; PAT is part of this request.
 *   PAT + SAT + SCI -> allowed; PAT and SAT are part of this
 *                      request, so SCI is also unlocked.
 *   PAT + SCI       -> previous SAT is still required.
 *   SAT + SCI       -> previous PAT is still required.
 *   SAT only        -> previous PAT is required.
 *   SCI only        -> previous PAT + SAT are required.
 */
$currentRequestHasPAT = $isPAT;
$currentRequestHasSAT = $isSAT;


/* =========================================================
   SELECTED SERVICE SUMMARY FOR DISPLAY
========================================================= */

$serviceSummary = [];

foreach (
    $services as $selectedService
) {

    $serviceSummary[] =
        htmlspecialchars(
            (string)$selectedService['service_name']
        );
}

/* =========================================================
   SELECTED ASSETS FROM SESSION
========================================================= */

$selectedAssetIds = [];

if (is_array($_SESSION['phase4_asset_ids'] ?? null)) {

    foreach ($_SESSION['phase4_asset_ids'] as $id) {

        $id = (int)$id;

        if ($id > 0) {
            $selectedAssetIds[] = $id;
        }
    }

    $selectedAssetIds = array_values(
        array_unique($selectedAssetIds)
    );
}

/* =========================================================
   ERROR
========================================================= */

$error = '';
$successMessage = '';
$deleteActionHandled = false;


/* =========================================================
   PERMANENT ASSET DELETE HELPERS
========================================================= */

function nletaGetDatabaseName(mysqli $db): string
{
    $result = $db->query("SELECT DATABASE() AS db_name");

    if (!$result) {
        throw new Exception(
            'Unable to determine the active database.'
        );
    }

    $row = $result->fetch_assoc();

    $dbName = trim(
        (string)($row['db_name'] ?? '')
    );

    if ($dbName === '') {
        throw new Exception(
            'No active database is selected.'
        );
    }

    return $dbName;
}


function nletaGetForeignKeyChildren(
    mysqli $db,
    string $dbName,
    string $parentTable,
    string $parentColumn
): array {

    $stmt = $db->prepare("
        SELECT
            TABLE_NAME AS child_table,
            COLUMN_NAME AS child_column
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ?
          AND REFERENCED_TABLE_SCHEMA = ?
          AND REFERENCED_TABLE_NAME = ?
          AND REFERENCED_COLUMN_NAME = ?
          AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ");

    if (!$stmt) {
        throw new Exception(
            'Unable to inspect asset dependencies: ' .
            $db->error
        );
    }

    $stmt->bind_param(
        'ssss',
        $dbName,
        $dbName,
        $parentTable,
        $parentColumn
    );

    if (!$stmt->execute()) {
        $error = $stmt->error;

        $stmt->close();

        throw new Exception(
            'Unable to inspect asset dependencies: ' .
            $error
        );
    }

    $rows = [];

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $stmt->close();

    return $rows;
}


function nletaDeleteAssetDescendants(
    mysqli $db,
    string $dbName,
    string $parentTable,
    string $parentColumn,
    int $parentId,
    array &$visited
): void {

    $visitKey =
        strtolower($parentTable)
        . '::'
        . strtolower($parentColumn)
        . '::'
        . $parentId;

    if (isset($visited[$visitKey])) {
        return;
    }

    $visited[$visitKey] = true;

    $children =
        nletaGetForeignKeyChildren(
            $db,
            $dbName,
            $parentTable,
            $parentColumn
        );

    foreach ($children as $child) {

        $childTable =
            (string)$child['child_table'];

        $childColumn =
            (string)$child['child_column'];

        $safeTable =
            '`' .
            str_replace('`', '``', $childTable)
            . '`';

        $safeColumn =
            '`' .
            str_replace('`', '``', $childColumn)
            . '`';

        $columnsResult = $db->query(
            "SHOW COLUMNS FROM {$safeTable}"
        );

        if (!$columnsResult) {
            throw new Exception(
                'Unable to inspect table ' .
                $childTable .
                ': ' .
                $db->error
            );
        }

        $primaryColumn = null;

        $pkResult = $db->query(
            "SHOW KEYS FROM {$safeTable} WHERE Key_name = 'PRIMARY'"
        );

        if ($pkResult) {

            $pkRows = [];

            while (
                $pkRow =
                $pkResult->fetch_assoc()
            ) {
                $pkRows[] = $pkRow;
            }

            if (count($pkRows) === 1) {
                $primaryColumn =
                    (string)$pkRows[0]['Column_name'];
            }
        }

        if ($primaryColumn !== null) {

            $safePrimary =
                '`' .
                str_replace(
                    '`',
                    '``',
                    $primaryColumn
                )
                . '`';

            $select =
                $db->prepare("
                    SELECT {$safePrimary}
                    FROM {$safeTable}
                    WHERE {$safeColumn} = ?
                ");

            if (!$select) {
                throw new Exception(
                    'Unable to inspect dependent records in ' .
                    $childTable .
                    ': ' .
                    $db->error
                );
            }

            $select->bind_param(
                'i',
                $parentId
            );

            if (!$select->execute()) {

                $error = $select->error;

                $select->close();

                throw new Exception(
                    'Unable to inspect dependent records in ' .
                    $childTable .
                    ': ' .
                    $error
                );
            }

            $result =
                $select->get_result();

            $childIds = [];

            while (
                $childRow =
                $result->fetch_assoc()
            ) {

                $childIds[] =
                    (int)$childRow[$primaryColumn];
            }

            $select->close();

            foreach ($childIds as $childId) {

                nletaDeleteAssetDescendants(
                    $db,
                    $dbName,
                    $childTable,
                    $primaryColumn,
                    $childId,
                    $visited
                );
            }
        }

        $delete =
            $db->prepare("
                DELETE FROM {$safeTable}
                WHERE {$safeColumn} = ?
            ");

        if (!$delete) {
            throw new Exception(
                'Unable to prepare deletion from ' .
                $childTable .
                ': ' .
                $db->error
            );
        }

        $delete->bind_param(
            'i',
            $parentId
        );

        if (!$delete->execute()) {

            $error = $delete->error;

            $delete->close();

            throw new Exception(
                'Unable to delete dependent records from ' .
                $childTable .
                ': ' .
                $error
            );
        }

        $delete->close();
    }
}


/* =========================================================
   PERMANENT DELETE SELECTED OWNER ASSET
========================================================= */

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['delete_asset'])
) {

    $deleteActionHandled = true;

    $deleteAssetId =
        (int)($_POST['delete_asset_id'] ?? 0);

    $postedToken =
        (string)($_POST['csrf_token'] ?? '');

    if (
        !$postedToken
        ||
        !hash_equals(
            $csrfToken,
            $postedToken
        )
    ) {

        $error =
            'Security validation failed. Please try again.';

    } elseif (
        $deleteAssetId <= 0
    ) {

        $error =
            'Invalid Asset ID.';

    } else {

        $assetCheck =
            $conn->prepare("
                SELECT
                    id,
                    display_asset_id,
                    asset_name
                FROM assets
                WHERE id = ?
                  AND owner_id = ?
                LIMIT 1
            ");

        if (!$assetCheck) {

            $error =
                'Unable to verify the selected asset: ' .
                $conn->error;

        } else {

            $assetCheck->bind_param(
                'ii',
                $deleteAssetId,
                $owner_id
            );

            $assetCheck->execute();

            $assetToDelete =
                $assetCheck
                ->get_result()
                ->fetch_assoc();

            $assetCheck->close();

            if (!$assetToDelete) {

                $error =
                    'Asset not found or it does not belong to your account.';

            } else {

                if (
                    is_array(
                        $_SESSION['phase4_asset_ids']
                        ?? null
                    )
                ) {

                    $_SESSION['phase4_asset_ids'] =
                        array_values(
                            array_filter(
                                array_map(
                                    'intval',
                                    $_SESSION['phase4_asset_ids']
                                ),
                                function ($id) use (
                                    $deleteAssetId
                                ) {
                                    return $id !==
                                        $deleteAssetId;
                                }
                            )
                        );
                }

                $conn->begin_transaction();

                try {

                    $dbName =
                        nletaGetDatabaseName(
                            $conn
                        );

                    $visited = [];

                    nletaDeleteAssetDescendants(
                        $conn,
                        $dbName,
                        'assets',
                        'id',
                        $deleteAssetId,
                        $visited
                    );

                    $deleteAsset =
                        $conn->prepare("
                            DELETE FROM assets
                            WHERE id = ?
                              AND owner_id = ?
                            LIMIT 1
                        ");

                    if (!$deleteAsset) {

                        throw new Exception(
                            'Unable to prepare Asset deletion: ' .
                            $conn->error
                        );
                    }

                    $deleteAsset->bind_param(
                        'ii',
                        $deleteAssetId,
                        $owner_id
                    );

                    if (
                        !$deleteAsset->execute()
                    ) {

                        $deleteError =
                            $deleteAsset->error;

                        $deleteAsset->close();

                        throw new Exception(
                            'Unable to delete Asset: ' .
                            $deleteError
                        );
                    }

                    if (
                        $deleteAsset->affected_rows <= 0
                    ) {

                        $deleteAsset->close();

                        throw new Exception(
                            'Asset could not be deleted.'
                        );
                    }

                    $deleteAsset->close();

                    $conn->commit();

                    $selectedAssetIds =
                        array_values(
                            array_filter(
                                $selectedAssetIds,
                                function ($id) use (
                                    $deleteAssetId
                                ) {
                                    return
                                        (int)$id !==
                                        $deleteAssetId;
                                }
                            )
                        );

                    $successMessage =
                        'Asset ' .
                        (
                            $assetToDelete['display_asset_id']
                            ?: '#' . $deleteAssetId
                        ) .
                        ' and its linked database records were permanently deleted.';

                } catch (Throwable $e) {

                    $conn->rollback();

                    $error =
                        'Unable to permanently delete the asset: ' .
                        $e->getMessage();
                }
            }
        }
    }
}


/* =========================================================
   LOAD OWNER ASSETS
========================================================= */

$assets = [];

$stmt = $conn->prepare("
    SELECT
        id,
        display_asset_id,
        asset_name,
        asset_type,
        category,
        location,
        status
    FROM assets
    WHERE owner_id = ?
    ORDER BY
        display_asset_id ASC,
        id ASC
");

$stmt->bind_param(
    "i",
    $owner_id
);

$stmt->execute();

$result =
    $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $assets[] = $row;
}

$stmt->close();


/* =========================================================
   PAT / SAT / SCI ELIGIBILITY
   ---------------------------------------------------------
   BUSINESS RULES:
   PAT:
       Directly selectable.

   SAT:
       PAT must already be completed/accepted for the SAME
       asset.
       0-60 days  : SAT allowed.
       61-90 days : Admin validation required; SAT blocked
                    until validation is approved.
       >90 days   : Fresh PAT mandatory; SAT blocked.

   SCI:
       PAT + SAT must both already be completed/accepted for
       the SAME asset.

   IMPORTANT:
       This is ONLY for Owner technical service selection.
       Technician Monthly Maintenance workflow is untouched.
========================================================= */

$satOk = [];
$satValidationRequired = [];
$satFreshPatRequired = [];
$patDates = [];

$sciOk = [];
$sciMissingPat = [];
$sciMissingSat = [];

/*
 * Read historical PAT/SAT inspection jobs for every owner asset.
 * PAT completion date is taken from checklist.pat_completed_at_ist
 * because the existing PAT close workflow stores that timestamp.
 */
$inspectionHistory = [];

if ($assets) {

    $historyStmt = $conn->prepare("
        SELECT
            mj.id,
            mj.asset_id,
            mj.status AS job_status,
            mj.sat_status,
            mj.checklist,
            mj.created_at,
            mj.updated_at,
            UPPER(TRIM(COALESCE(sc.service_code, ''))) AS service_code
        FROM maintenance_jobs mj
        LEFT JOIN saf_contracts sc
            ON sc.id = mj.saf_id
        WHERE
            mj.owner_id = ?
            AND mj.job_type = 'saf_inspection'
            AND mj.asset_id IS NOT NULL
        ORDER BY mj.id DESC
    ");

    if ($historyStmt) {

        $historyStmt->bind_param(
            'i',
            $owner_id
        );

        if ($historyStmt->execute()) {

            $historyResult =
                $historyStmt->get_result();

            while ($historyRow = $historyResult->fetch_assoc()) {

                $assetId = (int)$historyRow['asset_id'];

                if (!isset($inspectionHistory[$assetId])) {
                    $inspectionHistory[$assetId] = [];
                }

                $decoded = [];

                if (!empty($historyRow['checklist'])) {

                    $tmp =
                        json_decode(
                            $historyRow['checklist'],
                            true
                        );

                    if (is_array($tmp)) {
                        $decoded = $tmp;
                    }
                }

                $historyRow['decoded_checklist'] = $decoded;

                $inspectionHistory[$assetId][] = $historyRow;
            }
        }

        $historyStmt->close();
    }
}


/*
 * Work out PAT/SAT completion for each asset.
 */
foreach ($assets as $asset) {

    $assetId = (int)$asset['id'];

    $patComplete = false;
    $satComplete = false;
    $patCompletedDate = null;

    $rows =
        $inspectionHistory[$assetId]
        ?? [];

    foreach ($rows as $jobRow) {

        $code =
            strtoupper(
                trim(
                    (string)($jobRow['service_code'] ?? '')
                )
            );

        $jobStatus =
            strtolower(
                trim(
                    (string)($jobRow['job_status'] ?? '')
                )
            );

        $jobSatStatus =
            strtolower(
                trim(
                    (string)($jobRow['sat_status'] ?? '')
                )
            );

        $checklist =
            is_array($jobRow['decoded_checklist'] ?? null)
            ? $jobRow['decoded_checklist']
            : [];

        $checklistPatStatus =
            strtolower(
                trim(
                    (string)($checklist['pat_status'] ?? '')
                )
            );

        $checklistSatStatus =
            strtolower(
                trim(
                    (string)($checklist['sat_status'] ?? '')
                )
            );

        /*
         * PAT completion.
         */
        if ($code === 'PAT') {

            if (
                $jobStatus === 'closed'
                &&
                (
                    $checklistPatStatus === 'closed'
                    ||
                    $jobSatStatus === 'unlocked'
                )
            ) {

                $patComplete = true;

                $dateValue =
                    trim(
                        (string)(
                            $checklist['pat_completed_at_ist']
                            ?? ''
                        )
                    );

                if ($dateValue !== '') {

                    $candidate = strtotime($dateValue);

                    if (
                        $candidate !== false
                        &&
                        (
                            $patCompletedDate === null
                            ||
                            $candidate > $patCompletedDate
                        )
                    ) {
                        $patCompletedDate = $candidate;
                    }
                }
            }
        }

        /*
         * Legacy/combined PAT_SAT records.
         */
        if ($code === 'PAT_SAT') {

            if ($checklistPatStatus === 'closed') {

                $patComplete = true;

                $dateValue =
                    trim(
                        (string)(
                            $checklist['pat_completed_at_ist']
                            ?? ''
                        )
                    );

                if ($dateValue !== '') {

                    $candidate = strtotime($dateValue);

                    if (
                        $candidate !== false
                        &&
                        (
                            $patCompletedDate === null
                            ||
                            $candidate > $patCompletedDate
                        )
                    ) {
                        $patCompletedDate = $candidate;
                    }
                }
            }

            if (
                $jobStatus === 'closed'
                &&
                $checklistSatStatus === 'closed'
            ) {
                $satComplete = true;
            }
        }

        /*
         * SAT completion.
         */
        if ($code === 'SAT') {

            if (
                $jobStatus === 'closed'
                &&
                (
                    $checklistSatStatus === 'closed'
                    ||
                    $jobSatStatus === 'closed'
                )
            ) {
                $satComplete = true;
            }
        }
    }

    /*
     * Store latest PAT completion date.
     */
    $patDates[$assetId] = $patCompletedDate;

    /*
     * SAT eligibility.
     */
    if ($patComplete && $patCompletedDate !== null) {

        $daysSincePat =
            (int)floor(
                max(
                    0,
                    time() - $patCompletedDate
                ) / 86400
            );

        if ($daysSincePat <= 60) {

            $satOk[$assetId] = true;

        } elseif ($daysSincePat <= 90) {

            /*
             * Annexure-A:
             * 61-90 days = validation required.
             *
             * Until Admin validation exists/approves, SAT
             * remains blocked on this page.
             */
            $satOk[$assetId] = false;
            $satValidationRequired[$assetId] = true;

        } else {

            /*
             * Beyond 90 days = fresh PAT mandatory.
             */
            $satOk[$assetId] = false;
            $satFreshPatRequired[$assetId] = true;
        }

    } else {

        $satOk[$assetId] = false;
    }

    /*
     * SCI:
     * PAT + SAT both mandatory.
     */
    $sciOk[$assetId] =
        $patComplete &&
        $satComplete;

    if (!$patComplete) {
        $sciMissingPat[$assetId] = true;
    }

    if (!$satComplete) {
        $sciMissingSat[$assetId] = true;
    }
}


/* =========================================================
   STEP 2 — SELECT ASSETS
========================================================= */

if (
    !$deleteActionHandled
    &&
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $postedToken = $_POST['csrf_token'] ?? '';

    if (
        !$postedToken ||
        !hash_equals($csrfToken, $postedToken)
    ) {

        $error =
            'Security validation failed. Please try again.';

    } else {

        $posted =
            $_POST['asset_ids'] ?? [];

        if (!is_array($posted)) {
            $posted = [];
        }

        $selectedAssetIds = [];

        foreach ($posted as $id) {

            $id = (int)$id;

            if ($id > 0) {
                $selectedAssetIds[] = $id;
            }
        }

        $selectedAssetIds =
            array_values(
                array_unique(
                    $selectedAssetIds
                )
            );

        if (!$selectedAssetIds) {

            $error =
                'Please select at least one Asset.';

        } else {

            $placeholders =
                implode(
                    ',',
                    array_fill(
                        0,
                        count($selectedAssetIds),
                        '?'
                    )
                );

            $types =
                str_repeat(
                    'i',
                    count($selectedAssetIds)
                );

            $sql = "
                SELECT id
                FROM assets
                WHERE owner_id = ?
                  AND id IN ($placeholders)
            ";

            $stmt =
                $conn->prepare($sql);

            $bindTypes =
                'i' . $types;

            $bindValues =
                array_merge(
                    [$owner_id],
                    $selectedAssetIds
                );

            $refs =
                [$bindTypes];

            foreach (
                $bindValues
                as $key => $value
            ) {
                $refs[] =
                    &$bindValues[$key];
            }

            call_user_func_array(
                [$stmt, 'bind_param'],
                $refs
            );

            $stmt->execute();

            $valid = [];

            $result =
                $stmt->get_result();

            while (
                $row =
                $result->fetch_assoc()
            ) {

                $valid[] =
                    (int)$row['id'];
            }

            $stmt->close();

            if (
                count($valid) !==
                count($selectedAssetIds)
            ) {

                $selectedAssetIds = [];

                $error =
                    'One or more selected assets do not belong to your account.';

            } else {

                /*
                 * =================================================
                 * SERVICE DEPENDENCY VALIDATION
                 * =================================================
                 *
                 * PAT  -> direct
                 *
                 * SAT  -> PAT required:
                 *        0-60 days  = allowed
                 *        61-90 days = Admin validation required
                 *        >90 days   = Fresh PAT mandatory
                 *
                 * SCI  -> PAT + SAT both required
                 *
                 * This server-side validation mirrors the UI and
                 * prevents bypassing disabled checkboxes.
                 */

                $dependencyError = '';

                foreach ($selectedAssetIds as $assetId) {

                    /*
                     * =================================================
                     * SAT VALIDATION
                     * =================================================
                     *
                     * If PAT is part of the current request, the
                     * current PAT satisfies SAT's prerequisite.
                     *
                     * If PAT is NOT part of the current request,
                     * historical PAT validation remains mandatory.
                     */
                    if ($isSAT && !$currentRequestHasPAT) {

                        if (
                            !empty(
                                $satValidationRequired[$assetId]
                            )
                        ) {

                            $dependencyError =
                                'SAT cannot be selected for Asset #' .
                                $assetId .
                                ' because the previous PAT is 61–90 days old. ' .
                                'Admin validation is required before SAT can proceed.';

                            break;
                        }

                        if (
                            !empty(
                                $satFreshPatRequired[$assetId]
                            )
                        ) {

                            $dependencyError =
                                'SAT cannot be selected for Asset #' .
                                $assetId .
                                ' because the previous PAT is more than 90 days old. ' .
                                'Fresh PAT is mandatory.';

                            break;
                        }

                        if (
                            empty(
                                $satOk[$assetId]
                            )
                        ) {

                            $dependencyError =
                                'SAT cannot be selected for Asset #' .
                                $assetId .
                                '. A completed/accepted PAT is required.';

                            break;
                        }
                    }

                    /*
                     * =================================================
                     * SCI VALIDATION
                     * =================================================
                     *
                     * PAT selected in this request satisfies SCI's
                     * PAT dependency.
                     *
                     * SAT selected in this request satisfies SCI's
                     * SAT dependency.
                     *
                     * Only missing prerequisites are checked against
                     * historical inspection records.
                     */
                    if ($isSCI) {

                        $sciPatSatisfied =
                            $currentRequestHasPAT ||
                            empty($sciMissingPat[$assetId]);

                        $sciSatSatisfied =
                            $currentRequestHasSAT ||
                            empty($sciMissingSat[$assetId]);

                        if (
                            !$sciPatSatisfied ||
                            !$sciSatSatisfied
                        ) {

                            $missing = [];

                            if (!$sciPatSatisfied) {
                                $missing[] = 'PAT';
                            }

                            if (!$sciSatSatisfied) {
                                $missing[] = 'SAT';
                            }

                            $missingText =
                                implode(
                                    ' + ',
                                    $missing
                                );

                            $dependencyError =
                                'SCI cannot be selected for Asset #' .
                                $assetId .
                                '. ' .
                                (
                                    $missingText !== ''
                                    ? $missingText . ' must be completed/accepted first.'
                                    : 'PAT + SAT must be completed/accepted first.'
                                );

                            break;
                        }
                    }
                }

                if ($dependencyError !== '') {

                    $error = $dependencyError;

                } else {

                    $_SESSION['phase4_asset_ids'] =
                        $selectedAssetIds;

                    header(
                        "Location: service-request-review.php"
                    );

                    exit;
                }
            }
        }
    }
}




include('../includes/header.php');
include('../includes/sidebar-owner.php');
?>

<style>

.phase4-wrapper {
    max-width: 1200px;
    margin: auto;
}

.phase4-header,
.select-box,
.phase4-actions {
    background: #fff;
    border-radius: 14px;
    padding: 22px;
    box-shadow: 0 2px 10px rgba(0,0,0,.08);
    margin-bottom: 20px;
}

.asset-grid {
    display: grid;
    grid-template-columns:
        repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.asset-card {
    position: relative;
    background: #fff;
    border: 2px solid #e5e7eb;
    border-radius: 14px;
    padding: 20px;
    transition: .15s;
}

.asset-card:hover {
    border-color: #86b7fe;
}

.asset-card.selected {
    border-color: #0d6efd;
    background: #f4f8ff;
}

.asset-delete-row {
    display: flex;
    justify-content: flex-end;
}

.asset-delete-form {
    margin: 0;
}

.asset-delete-form button {
    font-size: 12px;
}

.asset-delete-form button:hover {
    color: #fff;
}


/* =========================================================
   ONLY NEW ADDITION:
   SIMPLE "CLICK HERE ->" BESIDE CHECKBOX
========================================================= */

.asset-checkbox-area {
    position: absolute;
    top: 18px;
    right: 18px;

    display: flex;
    align-items: center;
    gap: 5px;

    z-index: 5;
}

.asset-click-here {
    font-size: 11px;
    color: #6c757d;
    white-space: nowrap;
    font-weight: 500;
}

.asset-checkbox {
    width: 22px;
    height: 22px;
    cursor: pointer;
    margin: 0;
    border: 2px solid #6c757d;
}


/* =========================================================
   EXISTING
========================================================= */

.asset-top {
    display: flex;
    gap: 13px;
    align-items: center;
    padding-right: 35px;
}

.asset-icon {
    width: 48px;
    height: 48px;
    border-radius: 11px;
    background: #eaf2ff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
}

.asset-id {
    font-weight: 700;
    font-size: 17px;
}

.asset-name {
    font-size: 13px;
    color: #6c757d;
}

.asset-details {
    display: grid;
    grid-template-columns:
        1fr 1fr;
    gap: 10px;
    border-top: 1px solid #eee;
    margin-top: 18px;
    padding-top: 15px;
}

.detail-label {
    font-size: 12px;
    color: #6c757d;
}

.detail-value {
    font-weight: 600;
    font-size: 13px;
}

.phase4-steps {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 20px;
}

.step {
    display: flex;
    gap: 8px;
    align-items: center;
    color: #6c757d;
}

.step.active {
    color: #0d6efd;
    font-weight: 600;
}

.step.done {
    color: #198754;
    font-weight: 600;
}

.num {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    background: #e9ecef;
    display: flex;
    align-items: center;
    justify-content: center;
}

.active .num {
    background: #0d6efd;
    color: #fff;
}

.done .num {
    background: #198754;
    color: #fff;
}

@media(max-width:768px) {

    .asset-grid {
        grid-template-columns: 1fr;
    }

    .asset-details {
        grid-template-columns: 1fr;
    }

    .phase4-actions {
        flex-direction: column;
        align-items: stretch;
    }

    .asset-checkbox-area {
        top: 14px;
        right: 14px;
    }

}

</style>

<div class="content">

<div class="phase4-wrapper">

<!-- =====================================================
     HEADER
===================================================== -->

<div class="phase4-header">

    <div
        class="d-flex justify-content-between
               align-items-start gap-3"
    >

        <div>

            <h3>

                <i class="bi bi-buildings me-2"></i>

                Step 2 — Select Assets

            </h3>

            <p class="text-muted mb-0">

                Select one or more Assets for the selected services.
                Lift, Escalator, and other supported Asset types can be selected.

            </p>

        </div>

        <div class="text-end">

            <a
                href="add-lift-escalator.php"
                class="btn btn-primary mb-2"
            >

                <i class="bi bi-plus-circle me-1"></i>

                Add Asset

            </a>

            <br>

            <small class="text-muted">

                Current Date & Time

            </small>

            <div class="fw-semibold">

                <?= htmlspecialchars(
                    date('d-m-Y h:i A')
                ) ?>

            </div>

        </div>

    </div>

    <div class="alert alert-light border mt-3 mb-0">

        <div class="fw-semibold mb-2">
            <i class="bi bi-clipboard-check me-1"></i>
            Selected Services
            <span class="badge bg-primary ms-1">
                <?= count($services) ?>
            </span>
        </div>

        <div class="d-flex flex-wrap gap-2">

            <?php foreach ($services as $selectedService): ?>

                <span class="badge bg-primary-subtle text-primary border">

                    <?= htmlspecialchars(
                        $selectedService['service_name']
                    ) ?>

                    <span class="ms-1">
                        (<?= htmlspecialchars(
                            $selectedService['service_code']
                        ) ?>)
                    </span>

                </span>

            <?php endforeach; ?>

        </div>

    </div>

    <div class="phase4-steps">

        <div class="step done">

            <span class="num">✓</span>

            Select Service

        </div>

        <span>→</span>

        <div class="step active">

            <span class="num">2</span>

            Add Assets

        </div>

        <span>→</span>

        <div class="step">

            <span class="num">3</span>

            Review & Submit

        </div>

    </div>

</div>


<?php if (!empty($successMessage)): ?>

<div class="alert alert-success alert-dismissible fade show">

    <i class="bi bi-check-circle-fill me-2"></i>

    <?= htmlspecialchars($successMessage) ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert"
    ></button>

</div>

<?php endif; ?>


<?php if ($error): ?>

<div class="alert alert-danger">

    <i
        class="bi bi-exclamation-triangle-fill me-2"
    ></i>

    <?= htmlspecialchars($error) ?>

</div>

<?php endif; ?>


<!-- =====================================================
     ASSET HEADER
===================================================== -->

<div
    class="
        select-box
        d-flex
        justify-content-between
        align-items-center
        gap-3
    "
>

    <div>

        <h5 class="mb-1">

            My Assets

        </h5>

        <div class="text-muted small">

            Click anywhere on an asset card to select it.
            The checkbox is optional.

            <br>

            <span class="text-danger">

                <i class="bi bi-exclamation-triangle me-1"></i>

                Permanent delete removes the asset and its
                linked database records.

            </span>

        </div>

    </div>

    <a
        href="add-lift-escalator.php"
        class="btn btn-success"
    >

        <i class="bi bi-plus-circle me-1"></i>

        Add Asset

    </a>

</div>


<!-- =====================================================
     NO ASSETS
===================================================== -->

<?php if (!$assets): ?>

<div class="select-box text-center py-5">

    <div class="fs-1 text-muted mb-3">

        <i class="bi bi-building-x"></i>

    </div>

    <h5>

        No Assets Found

    </h5>

    <p class="text-muted">

        You need to add your Asset
        before submitting this service request.

    </p>

    <a
        href="add-lift-escalator.php"
        class="btn btn-primary"
    >

        <i class="bi bi-plus-circle me-1"></i>

        Add Asset

    </a>

</div>

<?php else: ?>


<form
    method="POST"
    id="assetSelectionForm"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= htmlspecialchars(
        $csrfToken
    ) ?>"
>

<input
    type="hidden"
    name="delete_asset_id"
    id="deleteAssetId"
    value=""
>


<!-- =====================================================
     SELECT ALL
===================================================== -->

<div class="select-box">

    <div class="form-check">

        <input
            class="form-check-input"
            type="checkbox"
            id="selectAll"
        >

        <label
            class="form-check-label fw-semibold"
            for="selectAll"
        >

            Select All Eligible Assets

        </label>

        <?php if ($isSAT || $isSCI): ?>
        <div class="small text-muted mt-2">
            <?php if ($isSAT): ?>
                SAT:
                <?php if ($currentRequestHasPAT): ?>
                    PAT is selected in this same request, so the PAT dependency is satisfied.
                <?php else: ?>
                    A previously completed/accepted PAT is required.
                    61–90 days requires Admin validation; beyond 90 days requires fresh PAT.
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($isSCI): ?>
                <br>
                SCI:
                <?php if ($currentRequestHasPAT && $currentRequestHasSAT): ?>
                    PAT + SAT are selected in this same request, so SCI can follow SAT.
                <?php elseif ($currentRequestHasPAT): ?>
                    PAT is selected in this request; a previously completed/accepted SAT is still required.
                <?php elseif ($currentRequestHasSAT): ?>
                    SAT is selected in this request; a previously completed/accepted PAT is still required.
                <?php else: ?>
                    Previously completed/accepted PAT + SAT are required.
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

</div>


<!-- =====================================================
     ASSET GRID
===================================================== -->

<div class="asset-grid">

<?php foreach ($assets as $asset): ?>

<?php

$assetId =
    (int)$asset['id'];

/*
 * Asset eligibility depends on the selected service(s).
 * PAT itself has no dependency.
 */
$eligible = true;
$eligibilityMessage = '';

/*
 * =========================================================
 * CURRENT REQUEST-AWARE SERVICE DEPENDENCIES
 * =========================================================
 *
 * PAT selected in the current request:
 *     Satisfies PAT prerequisite for SAT/SCI.
 *
 * SAT selected in the current request:
 *     Satisfies SAT prerequisite for SCI.
 *
 * Therefore:
 *     PAT + SAT       -> allowed
 *     PAT + SAT + SCI -> allowed
 *     PAT + SCI       -> previous SAT still required
 *     SAT + SCI       -> previous PAT still required
 *     SAT only        -> previous PAT still required
 *     SCI only        -> previous PAT + SAT required
 */

/*
 * SAT dependency.
 *
 * If PAT is already selected in this request, SAT does NOT
 * ask for an older PAT record for this asset.
 *
 * Otherwise the normal historical PAT age rules apply.
 */
if ($isSAT && !$currentRequestHasPAT) {

    if (!empty($satValidationRequired[$assetId])) {

        $eligible = false;

        $eligibilityMessage =
            'SAT: Admin validation required (PAT age 61–90 days)';

    } elseif (!empty($satFreshPatRequired[$assetId])) {

        $eligible = false;

        $eligibilityMessage =
            'SAT: Fresh PAT mandatory (PAT age > 90 days)';

    } elseif (empty($satOk[$assetId])) {

        $eligible = false;

        $eligibilityMessage =
            'SAT: Completed/accepted PAT required';
    }
}

/*
 * SCI dependency.
 *
 * PAT requirement is satisfied by:
 *     current request PAT
 *     OR previous completed/accepted PAT
 *
 * SAT requirement is satisfied by:
 *     current request SAT
 *     OR previous completed/accepted SAT
 *
 * Historical SAT/PAT is checked only for the prerequisite
 * service(s) that were NOT selected in this request.
 */
if ($isSCI) {

    $sciPatSatisfied =
        $currentRequestHasPAT ||
        empty($sciMissingPat[$assetId]);

    $sciSatSatisfied =
        $currentRequestHasSAT ||
        empty($sciMissingSat[$assetId]);

    if (!$sciPatSatisfied || !$sciSatSatisfied) {

        $eligible = false;

        $missing = [];

        if (!$sciPatSatisfied) {
            $missing[] = 'PAT';
        }

        if (!$sciSatSatisfied) {
            $missing[] = 'SAT';
        }

        $eligibilityMessage =
            'SCI: ' .
            (
                $missing
                ? implode(' + ', $missing) . ' required'
                : 'PAT + SAT required'
            );
    }
}

$selected =
    $eligible &&
    in_array(
        $assetId,
        $selectedAssetIds,
        true
    );

$type =
    strtolower(
        (string)$asset['asset_type']
    );

?>

<div
    class="
        asset-card
        <?= $selected ? 'selected' : '' ?>

    "
>

<?php if ($eligible): ?>

<!-- =================================================
     SIMPLE CLICK HERE TEXT + EXISTING CHECKBOX
================================================= -->

<div class="asset-checkbox-area">

    <span class="asset-click-here">

        Click here ->

    </span>

    <input
        class="form-check-input asset-checkbox"
        type="checkbox"
        name="asset_ids[]"
        value="<?= $assetId ?>"
        <?= $selected ? 'checked' : '' ?>
        <?= !$eligible ? 'disabled' : '' ?>
    >

</div>

<?php endif; ?>


<div class="asset-top">

    <div class="asset-icon">

        <?= $type === 'escalator'
            ? '↗️'
            : '🛗'
        ?>

    </div>

    <div>

        <div class="asset-id">

            <?= htmlspecialchars(
                $asset['display_asset_id']
            ) ?>

        </div>

        <div class="asset-name">

            <?= htmlspecialchars(
                $asset['asset_name']
            ) ?>

        </div>

    </div>

</div>


<div class="asset-details">

    <div>

        <div class="detail-label">

            Asset Type

        </div>

        <div class="detail-value">

            <?= htmlspecialchars(
                $asset['asset_type']
            ) ?>

        </div>

    </div>


    <div>

        <div class="detail-label">

            Category

        </div>

        <div class="detail-value">

            <?= htmlspecialchars(
                $asset['category']
                ?: '—'
            ) ?>

        </div>

    </div>


    <div>

        <div class="detail-label">

            Location

        </div>

        <div class="detail-value">

            <?= htmlspecialchars(
                $asset['location']
                ?: '—'
            ) ?>

        </div>

    </div>


    <div>

        <div class="detail-label">

            Status

        </div>

        <div class="detail-value">

            <?= htmlspecialchars(
                $asset['status']
                ?: '—'
            ) ?>

        </div>

    </div>

</div>

<?php if (!$eligible && $eligibilityMessage !== ''): ?>

<div class="mt-3">
    <div class="alert alert-warning py-2 px-3 mb-0 small">
        <i class="bi bi-lock-fill me-1"></i>
        <?= htmlspecialchars($eligibilityMessage) ?>
    </div>
</div>

<?php endif; ?>


<!-- =====================================================
     PERMANENT DELETE
===================================================== -->

<div class="asset-delete-row mt-3 pt-3 border-top">

    <button
        type="submit"
        name="delete_asset"
        value="1"
        class="btn btn-sm btn-outline-danger"
        data-asset-id="<?= $assetId ?>"
        data-display-id="<?= htmlspecialchars(
            $asset['display_asset_id']
            ?: ('Asset #' . $assetId),
            ENT_QUOTES,
            'UTF-8'
        ) ?>"
    >

        <i class="bi bi-trash3 me-1"></i>

        Delete Asset Permanently

    </button>

</div>

</div>

<?php endforeach; ?>

</div>


<!-- =====================================================
     ACTIONS
===================================================== -->

<div
    class="
        phase4-actions
        mt-4
        d-flex
        justify-content-between
        align-items-center
        gap-3
    "
>

    <div class="text-muted small">

        <strong id="selectedText">

            0 assets selected

        </strong>

    </div>


    <div class="d-flex gap-2">

        <a
            href="service-selection.php"
            class="btn btn-outline-secondary"
        >

            Back

        </a>


        <a
            href="add-lift-escalator.php"
            class="btn btn-outline-primary"
        >

            <i class="bi bi-plus-circle me-1"></i>

            Add Asset

        </a>


        <button
            type="submit"
            class="btn btn-primary"
            id="continueBtn"
        >

            Continue to Review

            <i
                class="bi bi-arrow-right ms-1"
            ></i>

        </button>

    </div>

</div>

</form>

<?php endif; ?>

</div>

</div>


<?php include('../includes/footer.php'); ?>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const boxes =
            [
                ...document.querySelectorAll(
                    'input[name="asset_ids[]"]'
                )
            ];

        const selectAll =
            document.getElementById(
                'selectAll'
            );

        const selectedText =
            document.getElementById(
                'selectedText'
            );

        const form =
            document.getElementById(
                'assetSelectionForm'
            );


        function updateSelection() {

            let count = 0;

            boxes.forEach(
                function (box) {

                    if (box.checked) {
                        count++;
                    }

                    const card =
                        box.closest(
                            '.asset-card'
                        );

                    if (card) {

                        card.classList.toggle(
                            'selected',
                            box.checked
                        );
                    }

                }
            );


            if (selectedText) {

                selectedText.textContent =
                    count +
                    ' asset' +
                    (count === 1
                        ? ''
                        : 's') +
                    ' selected';
            }


            if (selectAll) {

                const enabledBoxes =
                    boxes.filter(
                        function (box) {
                            return !box.disabled;
                        }
                    );

                const enabledCount =
                    enabledBoxes.filter(
                        function (box) {
                            return box.checked;
                        }
                    ).length;

                selectAll.checked =
                    enabledBoxes.length > 0 &&
                    enabledCount === enabledBoxes.length;

                selectAll.indeterminate =
                    enabledCount > 0 &&
                    enabledCount < enabledBoxes.length;
            }
        }


        boxes.forEach(
            function (box) {

                box.addEventListener(
                    'change',
                    updateSelection
                );

            }
        );


        /*
         * Click anywhere on an asset card to select/unselect it.
         *
         * Checkbox itself and the Click here text are excluded.
         */
        document
            .querySelectorAll('.asset-card')
            .forEach(
                function (card) {

                    card.addEventListener(
                        'click',
                        function (event) {

                            if (
                                event.target.closest(
                                    'input'
                                )
                                ||
                                event.target.closest(
                                    'button'
                                )
                                ||
                                event.target.closest(
                                    'form'
                                )
                                ||
                                event.target.closest(
                                    'a'
                                )
                            ) {

                                return;
                            }


                            const checkbox =
                                card.querySelector(
                                    'input[name="asset_ids[]"]'
                                );


                            if (
                                !checkbox ||
                                checkbox.disabled
                            ) {

                                return;
                            }


                            checkbox.checked =
                                !checkbox.checked;


                            checkbox.dispatchEvent(
                                new Event(
                                    'change',
                                    {
                                        bubbles: true
                                    }
                                )
                            );

                        }
                    );

                }
            );


        /*
         * Keyboard accessibility.
         */
        document
            .querySelectorAll('.asset-card')
            .forEach(
                function (card) {

                    const checkbox =
                        card.querySelector(
                            'input[name="asset_ids[]"]'
                        );

                    if (!checkbox) {
                        return;
                    }

                    card.setAttribute(
                        'tabindex',
                        '0'
                    );

                    card.setAttribute(
                        'role',
                        'button'
                    );


                    card.addEventListener(
                        'keydown',
                        function (event) {

                            if (
                                event.key !== 'Enter'
                                &&
                                event.key !== ' '
                            ) {

                                return;
                            }

                            if (
                                event.target !== card
                            ) {

                                return;
                            }

                            event.preventDefault();

                            checkbox.checked =
                                !checkbox.checked;

                            checkbox.dispatchEvent(
                                new Event(
                                    'change',
                                    {
                                        bubbles: true
                                    }
                                )
                            );

                        }
                    );

                }
            );


        /*
         * Permanent delete confirmation.
         */
        window.confirmPermanentAssetDelete =
            function (displayId) {

                return confirm(
                    'WARNING: This will permanently delete ' +
                    displayId +
                    ' and its linked database records.\\n\\n' +
                    'This action cannot be undone.\\n\\n' +
                    'Do you want to continue?'
                );

            };


        if (selectAll) {

            selectAll.addEventListener(
                'change',
                function () {

                    boxes.forEach(
                        function (box) {

                            if (!box.disabled) {
                                box.checked =
                                    selectAll.checked;
                            }

                        }
                    );

                    updateSelection();

                }
            );
        }


        if (form) {

            form.addEventListener(
                'submit',
                function (event) {

                    const submitter =
                        event.submitter;


                    /*
                     * DELETE
                     */
                    const isDelete =
                        submitter
                        &&
                        submitter.name ===
                            'delete_asset';


                    if (isDelete) {

                        const hiddenDelete =
                            document.getElementById(
                                'deleteAssetId'
                            );


                        if (hiddenDelete) {

                            hiddenDelete.value =
                                submitter.dataset.assetId || '';

                        }


                        const confirmed =
                            confirmPermanentAssetDelete(
                                submitter.dataset.displayId || ''
                            );


                        if (!confirmed) {

                            event.preventDefault();

                            return;
                        }

                        return;
                    }


                    /*
                     * CONTINUE
                     */
                    const selected =
                        boxes.some(
                            function (box) {

                                return box.checked;

                            }
                        );


                    if (!selected) {

                        event.preventDefault();

                        alert(
                            'Please select at least one Asset.'
                        );

                        return;
                    }


                    const button =
                        document.getElementById(
                            'continueBtn'
                        );


                    if (button) {

                        button.disabled = true;

                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-1"></span>' +
                            'Loading...';
                    }

                }
            );
        }


        updateSelection();

    }
);

</script>