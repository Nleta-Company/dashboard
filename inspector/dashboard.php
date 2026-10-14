<?php
/* =========================================================
   NLETA INSPECTOR DASHBOARD
   =========================================================
   Inspector responsibilities ONLY:

   1. View assigned inspections
   2. View assigned services
   3. Open checklist
   4. Submit checklist

   Inspector does NOT handle:
   - Maintenance
   - Callbacks
   - Technician work
   - NOC
   - Insurance
   - SAF contract management
   - Certificate generation

   Timezone:
   Asia/Kolkata
========================================================= */

date_default_timezone_set('Asia/Kolkata');

require_once '../config/db.php';
require_once '../config/auth.php';
require_once '../config/security.php';

require_role('inspector');

$inspectorId = (int)(
    $_SESSION['uid']
    ?? $_SESSION['user_id']
    ?? 0
);

if ($inspectorId <= 0) {
    header('Location: ../login.php');
    exit;
}

/* =========================================================
   HELPERS
========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function tableExists($conn, $table)
{
    $safeTable = $conn->real_escape_string($table);

    $result = $conn->query(
        "SHOW TABLES LIKE '{$safeTable}'"
    );

    return (
        $result &&
        $result->num_rows > 0
    );
}

function columnExists($conn, $table, $column)
{
    if (!tableExists($conn, $table)) {
        return false;
    }

    $safeColumn = $conn->real_escape_string($column);

    $result = $conn->query(
        "SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'"
    );

    return (
        $result &&
        $result->num_rows > 0
    );
}

/* =========================================================
   INSPECTOR PROFILE
========================================================= */

$inspector = [
    'id'    => $inspectorId,
    'name'  => $_SESSION['name'] ?? 'Inspector',
    'email' => ''
];

$profileStmt = $conn->prepare("
    SELECT
        id,
        name,
        email,
        role,
        account_status,
        workflow_stage
    FROM users
    WHERE id = ?
      AND role = 'inspector'
    LIMIT 1
");

if ($profileStmt) {

    $profileStmt->bind_param(
        'i',
        $inspectorId
    );

    if ($profileStmt->execute()) {

        $profile = $profileStmt
            ->get_result()
            ->fetch_assoc();

        if ($profile) {
            $inspector = array_merge(
                $inspector,
                $profile
            );
        }
    }

    $profileStmt->close();
}

/* =========================================================
   COUNTERS
========================================================= */

$totalAssigned       = 0;
$pendingChecklists   = 0;
$submittedChecklists = 0;
$completedServices   = 0;
$ownerApproved       = 0;
$ownerReturned       = 0;

/* =========================================================
   ASSIGNMENT DATA
========================================================= */

$assignmentRows = [];

$assignmentTableAvailable = false;
$maintenanceInspectorAvailable = false;

$assignmentTableNames = [
    'inspector_assignments',
    'owner_inspector_assignments'
];

$assignmentTable = null;

foreach ($assignmentTableNames as $candidate) {

    if (tableExists($conn, $candidate)) {
        $assignmentTable = $candidate;
        break;
    }
}

if ($assignmentTable !== null) {
    $assignmentTableAvailable = true;
}

if (
    tableExists($conn, 'maintenance_jobs')
    &&
    columnExists($conn, 'maintenance_jobs', 'inspector_id')
) {
    $maintenanceInspectorAvailable = true;
}

/* =========================================================
   LOAD DEDICATED INSPECTOR ASSIGNMENTS
========================================================= */

if ($assignmentTableAvailable) {

    $hasId =
        columnExists(
            $conn,
            $assignmentTable,
            'id'
        );

    $hasInspectorId =
        columnExists(
            $conn,
            $assignmentTable,
            'inspector_id'
        );

    $hasOwnerId =
        columnExists(
            $conn,
            $assignmentTable,
            'owner_id'
        );

    $hasAssetId =
        columnExists(
            $conn,
            $assignmentTable,
            'asset_id'
        );

    $hasServiceId =
        columnExists(
            $conn,
            $assignmentTable,
            'service_id'
        );

    $hasStatus =
        columnExists(
            $conn,
            $assignmentTable,
            'status'
        );

    $hasAssignedAt =
        columnExists(
            $conn,
            $assignmentTable,
            'assigned_at'
        );

    if ($hasInspectorId) {

        $assignmentIdField =
            $hasId
                ? "ia.id AS assignment_id"
                : "0 AS assignment_id";

        $ownerIdField =
            $hasOwnerId
                ? "ia.owner_id"
                : "NULL";

        $assetIdField =
            $hasAssetId
                ? "ia.asset_id"
                : "NULL";

        $serviceIdField =
            $hasServiceId
                ? "ia.service_id"
                : "NULL";

        $statusField =
            $hasStatus
                ? "ia.status"
                : "'assigned'";

        $assignedAtField =
            $hasAssignedAt
                ? "ia.assigned_at"
                : "NULL";

        $ownerNameField =
            $hasOwnerId
                ? "owner_user.name AS owner_name"
                : "'—' AS owner_name";

        $assetNameField =
            $hasAssetId
                ? "a.asset_name"
                : "'—' AS asset_name";

        $displayAssetIdField =
            $hasAssetId
                ? "a.display_asset_id"
                : "'' AS display_asset_id";

        $locationField =
            $hasAssetId
                ? "a.location"
                : "'' AS location";

        $ownerJoin =
            $hasOwnerId
                ? "
                    LEFT JOIN users owner_user
                        ON owner_user.id = ia.owner_id
                       AND owner_user.role = 'owner'
                  "
                : "";

        $assetJoin =
            $hasAssetId
                ? "
                    LEFT JOIN assets a
                        ON a.id = ia.asset_id
                  "
                : "";

        $sql = "
            SELECT
                {$assignmentIdField},
                {$ownerIdField} AS owner_id,
                {$assetIdField} AS asset_id,
                {$serviceIdField} AS service_id,
                {$statusField} AS assignment_status,
                {$assignedAtField} AS assigned_at,
                {$ownerNameField},
                {$assetNameField},
                {$displayAssetIdField},
                {$locationField}
            FROM `{$assignmentTable}` ia
            {$ownerJoin}
            {$assetJoin}
            WHERE ia.inspector_id = ?
        ";

        if ($hasId) {
            $sql .= " ORDER BY ia.id DESC";
        }

        $stmt = $conn->prepare($sql);

        if ($stmt) {

            $stmt->bind_param(
                'i',
                $inspectorId
            );

            if ($stmt->execute()) {

                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $assignmentRows[] = $row;
                }
            }

            $stmt->close();
        }
    }
}

/* =========================================================
   FALLBACK — MAINTENANCE JOBS INSPECTOR ID
========================================================= */

if (
    empty($assignmentRows)
    &&
    $maintenanceInspectorAvailable
) {

    $jobStmt = $conn->prepare("
        SELECT
            mj.id AS assignment_id,
            mj.owner_id,
            mj.asset_id,
            mj.service_id,
            mj.status AS assignment_status,
            NULL AS assigned_at,
            u.name AS owner_name,
            a.asset_name,
            a.display_asset_id,
            a.location
        FROM maintenance_jobs mj
        LEFT JOIN users u
            ON u.id = mj.owner_id
           AND u.role = 'owner'
        LEFT JOIN assets a
            ON a.id = mj.asset_id
        WHERE mj.inspector_id = ?
          AND (
              mj.job_type = 'saf_inspection'
              OR mj.job_type IS NULL
          )
        ORDER BY mj.id DESC
    ");

    if ($jobStmt) {

        $jobStmt->bind_param(
            'i',
            $inspectorId
        );

        if ($jobStmt->execute()) {

            $result = $jobStmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $assignmentRows[] = $row;
            }
        }

        $jobStmt->close();
    }
}

/* =========================================================
   ENRICH ASSIGNMENTS WITH SAF JOB / CHECKLIST STATE
   ---------------------------------------------------------
========================================================= */

if (
    !empty($assignmentRows)
    &&
    tableExists($conn, 'maintenance_jobs')
) {

    $hasMjId =
        columnExists(
            $conn,
            'maintenance_jobs',
            'id'
        );

    $hasMjInspectorId =
        columnExists(
            $conn,
            'maintenance_jobs',
            'inspector_id'
        );

    $hasMjAssetId =
        columnExists(
            $conn,
            'maintenance_jobs',
            'asset_id'
        );

    $hasMjServiceId =
        columnExists(
            $conn,
            'maintenance_jobs',
            'service_id'
        );

    $hasMjJobType =
        columnExists(
            $conn,
            'maintenance_jobs',
            'job_type'
        );

    $hasMjStatus =
        columnExists(
            $conn,
            'maintenance_jobs',
            'status'
        );

    $hasMjChecklist =
        columnExists(
            $conn,
            'maintenance_jobs',
            'checklist'
        );

    foreach ($assignmentRows as &$assignment) {

        $assignment['job_id'] = 0;
        $assignment['job_status'] = '';
        $assignment['checklist'] = '';
        $assignment['owner_remark'] = '';
        $assignment['fixed_nc_count'] = 0;
        $assignment['floor_nc_count'] = 0;
        $assignment['nc_count'] = 0;
        $assignment['final_status'] = '';

        $assetId = (int)(
            $assignment['asset_id'] ?? 0
        );

        $serviceId =
            $assignment['service_id']
            ?? null;

        if (
            $hasMjId
            &&
            $hasMjAssetId
            &&
            $assetId > 0
        ) {

            $hasMjSafId = columnExists(
                $conn,
                'maintenance_jobs',
                'saf_id'
            );

            $select = [
                "mj.id AS job_id",
                $hasMjSafId
                    ? "mj.saf_id AS saf_id"
                    : "NULL AS saf_id",
                $hasMjStatus
                    ? "mj.status AS job_status"
                    : "'' AS job_status",
                $hasMjChecklist
                    ? "mj.checklist"
                    : "NULL AS checklist"
            ];

            if (
                columnExists(
                    $conn,
                    'maintenance_jobs',
                    'owner_remark'
                )
            ) {
                $select[] = "mj.owner_remark";
            } else {
                $select[] = "NULL AS owner_remark";
            }

            $jobSql =
                "SELECT "
                . implode(", ", $select)
                . " FROM maintenance_jobs mj
                   WHERE mj.asset_id = ?";

            $types = "i";
            $params = [
                $assetId
            ];

            if ($hasMjInspectorId) {

                $jobSql .=
                    " AND mj.inspector_id = ?";

                $types .= "i";

                $params[] =
                    $inspectorId;
            }

            if ($hasMjJobType) {

                $jobSql .=
                    " AND mj.job_type = 'saf_inspection'";
            }

            if (
                $hasMjServiceId
                &&
                $serviceId !== null
                &&
                $serviceId !== ''
            ) {

                $jobSql .=
                    " AND mj.service_id = ?";

                $types .= "i";

                $params[] =
                    (int)$serviceId;
            }

            $jobSql .=
                " ORDER BY mj.id DESC LIMIT 1";

            $jobStmt =
                $conn->prepare(
                    $jobSql
                );

            if ($jobStmt) {

                $bind = [
                    $types
                ];

                foreach (
                    $params
                    as $key => $value
                ) {
                    $bind[] =
                        &$params[$key];
                }

                call_user_func_array(
                    [
                        $jobStmt,
                        'bind_param'
                    ],
                    $bind
                );

                if (
                    $jobStmt->execute()
                ) {

                    $jobRow =
                        $jobStmt
                        ->get_result()
                        ->fetch_assoc();

                    if ($jobRow) {

                        $assignment['job_id'] =
                            (int)(
                                $jobRow['job_id']
                                ?? 0
                            );

                        $assignment['job_status'] =
                            (string)(
                                $jobRow['job_status']
                                ?? ''
                            );

                        $assignment['saf_id'] =
                            (int)(
                                $jobRow['saf_id']
                                ?? 0
                            );

                        $assignment['checklist'] =
                            (string)(
                                $jobRow['checklist']
                                ?? ''
                            );

                        $assignment['owner_remark'] =
                            (string)(
                                $jobRow['owner_remark']
                                ?? ''
                            );
                    }
                }

                $jobStmt->close();
            }
        }

        /*
         * Fallback assignment itself may be a maintenance job.
         */
        if (
            (int)$assignment['job_id'] <= 0
            &&
            isset($assignment['assignment_id'])
            &&
            $maintenanceInspectorAvailable
        ) {
            $assignment['job_id'] =
                (int)$assignment['assignment_id'];
        }

        /* -------------------------------------------------
           Parse checklist JSON for NC count only.
        ------------------------------------------------- */

        $decodedChecklist = [];

        if (
            !empty(
                $assignment['checklist']
            )
        ) {

            $decodedChecklist =
                json_decode(
                    (string)$assignment['checklist'],
                    true
                );

            if (
                !is_array(
                    $decodedChecklist
                )
            ) {
                $decodedChecklist = [];
            }
        }

        $fixedNc = 0;
        $floorNc = 0;

        $results =
            is_array(
                $decodedChecklist['results']
                ?? null
            )
                ? $decodedChecklist['results']
                : [];

        foreach (
            $results
            as $resultRow
        ) {

            if (
                is_array(
                    $resultRow
                )
            ) {

                $resultValue =
                    strtoupper(
                        trim(
                            (string)(
                                $resultRow['result']
                                ?? ''
                            )
                        )
                    );

                if (
                    $resultValue === 'NC'
                ) {
                    $fixedNc++;
                }

            } elseif (
                strtoupper(
                    trim(
                        (string)$resultRow
                    )
                ) === 'NC'
            ) {

                $fixedNc++;
            }
        }

        /*
         * Common floor-result containers.
         */
        $floorContainers = [];

        foreach (
            [
                'floor_results',
                'floor_checklist',
                'floor_based_results'
            ]
            as $key
        ) {

            if (
                isset(
                    $decodedChecklist[$key]
                )
                &&
                is_array(
                    $decodedChecklist[$key]
                )
            ) {

                $floorContainers[] =
                    $decodedChecklist[$key];
            }
        }

        foreach (
            $floorContainers
            as $container
        ) {

            foreach (
                $container
                as $floorRow
            ) {

                if (
                    !is_array(
                        $floorRow
                    )
                ) {
                    continue;
                }

                $candidate =
                    $floorRow['result']
                    ?? null;

                if (
                    $candidate !== null
                ) {

                    if (
                        is_array(
                            $candidate
                        )
                    ) {

                        foreach (
                            $candidate
                            as $value
                        ) {

                            $valueResult =
                                is_array($value)
                                    ? (
                                        $value['result']
                                        ?? ''
                                    )
                                    : $value;

                            if (
                                strtoupper(
                                    trim(
                                        (string)$valueResult
                                    )
                                ) === 'NC'
                            ) {
                                $floorNc++;
                            }
                        }

                    } elseif (
                        strtoupper(
                            trim(
                                (string)$candidate
                            )
                        ) === 'NC'
                    ) {

                        $floorNc++;
                    }

                } else {

                    foreach (
                        $floorRow
                        as $value
                    ) {

                        if (
                            is_array(
                                $value
                            )
                            &&
                            strtoupper(
                                trim(
                                    (string)(
                                        $value['result']
                                        ?? ''
                                    )
                                )
                            ) === 'NC'
                        ) {
                            $floorNc++;
                        }
                    }
                }
            }
        }

        $assignment['fixed_nc_count'] =
            $fixedNc;

        $assignment['floor_nc_count'] =
            $floorNc;

        $assignment['nc_count'] =
            $fixedNc + $floorNc;

        $assignment['final_status'] =
            strtoupper(
                trim(
                    (string)(
                        $decodedChecklist['final_status']
                        ?? ''
                    )
                )
            );


        /*
         * Resolve proposed inspection date from SAF contract.
         */
        $assignment['inspection_date'] = '';

        $safIdForDate = (int)($assignment['saf_id'] ?? 0);

        if (
            $safIdForDate > 0
            && tableExists($conn, 'saf_contracts')
            && columnExists(
                $conn,
                'saf_contracts',
                'proposed_inspection_date'
            )
        ) {
            $inspectionDateStmt = $conn->prepare("
                SELECT proposed_inspection_date
                FROM saf_contracts
                WHERE id = ?
                LIMIT 1
            ");

            if ($inspectionDateStmt) {
                $inspectionDateStmt->bind_param(
                    'i',
                    $safIdForDate
                );

                if ($inspectionDateStmt->execute()) {
                    $inspectionDateRow =
                        $inspectionDateStmt
                        ->get_result()
                        ->fetch_assoc();

                    if ($inspectionDateRow) {
                        $assignment['inspection_date'] =
                            (string)(
                                $inspectionDateRow[
                                    'proposed_inspection_date'
                                ] ?? ''
                            );
                    }
                }

                $inspectionDateStmt->close();
            }
        }

        /* -------------------------------------------------
           Resolve SERVICE NAME
           Show actual service name instead of Service #ID.
        ------------------------------------------------- */

        $assignment['service_name'] = '';

        $resolvedServiceId = (int)(
            $assignment['service_id'] ?? 0
        );

        if (
            $resolvedServiceId > 0
            &&
            tableExists($conn, 'service_catalog')
        ) {

            $serviceNameColumn = null;

            foreach (
                ['service_name', 'name', 'title']
                as $candidateColumn
            ) {

                if (
                    columnExists(
                        $conn,
                        'service_catalog',
                        $candidateColumn
                    )
                ) {

                    $serviceNameColumn =
                        $candidateColumn;

                    break;
                }
            }

            if ($serviceNameColumn !== null) {

                $serviceNameSql = "
                    SELECT
                        `{$serviceNameColumn}` AS service_name
                    FROM service_catalog
                    WHERE id = ?
                    LIMIT 1
                ";

                $serviceNameStmt =
                    $conn->prepare(
                        $serviceNameSql
                    );

                if ($serviceNameStmt) {

                    $serviceNameStmt->bind_param(
                        'i',
                        $resolvedServiceId
                    );

                    if (
                        $serviceNameStmt->execute()
                    ) {

                        $serviceNameRow =
                            $serviceNameStmt
                            ->get_result()
                            ->fetch_assoc();

                        if ($serviceNameRow) {

                            $assignment[
                                'service_name'
                            ] =
                                trim(
                                    (string)(
                                        $serviceNameRow[
                                            'service_name'
                                        ]
                                        ?? ''
                                    )
                                );
                        }
                    }

                    $serviceNameStmt->close();
                }
            }
        }

        /*
         * If service_catalog is unavailable or the ID
         * does not resolve, try service_requests/service
         * information already associated with the job.
         */
        if (
            $assignment['service_name'] === ''
            &&
            tableExists($conn, 'saf_contracts')
            &&
            !empty($assignment['job_id'])
        ) {

            $safServiceStmt =
                $conn->prepare("
                    SELECT
                        sc.service_name,
                        sc.service_code
                    FROM maintenance_jobs mj
                    INNER JOIN saf_contracts sc
                        ON sc.id = mj.saf_id
                    WHERE mj.id = ?
                    LIMIT 1
                ");

            if ($safServiceStmt) {

                $jobIdForService =
                    (int)$assignment['job_id'];

                $safServiceStmt->bind_param(
                    'i',
                    $jobIdForService
                );

                if (
                    $safServiceStmt->execute()
                ) {

                    $safServiceRow =
                        $safServiceStmt
                        ->get_result()
                        ->fetch_assoc();

                    if ($safServiceRow) {

                        $assignment[
                            'service_name'
                        ] =
                            trim(
                                (string)(
                                    $safServiceRow[
                                        'service_name'
                                    ]
                                    ?? ''
                                )
                            );

                        if (
                            $assignment[
                                'service_name'
                            ] === ''
                        ) {

                            $assignment[
                                'service_name'
                            ] =
                                trim(
                                    (string)(
                                        $safServiceRow[
                                            'service_code'
                                        ]
                                        ?? ''
                                    )
                                );
                        }
                    }
                }

                $safServiceStmt->close();
            }
        }
    }

    unset($assignment);
}

/* =========================================================
   SEPARATE ACTIVE ASSIGNMENTS FROM SUBMITTED INSPECTIONS
   ---------------------------------------------------------
   Active list:
     assigned / pending / in_progress / inspection_pending

   Submitted list:
     submitted / pending_owner / under_review /
     pending_verification / completed / approved / closed /
     rejected / returned / cancelled
========================================================= */

$activeAssignmentRows    = [];
$submittedAssignmentRows = [];

$submittedStatusValues = [
    'submitted',
    'pending_owner',
    'under_review',
    'pending_verification',
    'completed',
    'approved',
    'closed',
    'rejected',
    'returned',
    'cancelled'
];

foreach ($assignmentRows as $assignment) {

    $assignmentStatus = strtolower(
        trim(
            (string)($assignment['assignment_status'] ?? '')
        )
    );

    $jobStatus = strtolower(
        trim(
            (string)($assignment['job_status'] ?? '')
        )
    );

    /*
     * Once an inspection/checklist has been submitted or the
     * maintenance job has moved beyond active inspection,
     * remove it from "Assigned Inspections" and show it below
     * in "Submitted Inspections".
     *
     * final_status is also treated as submitted because the
     * checklist has reached a final result.
     */
    $isSubmitted =
        in_array($assignmentStatus, $submittedStatusValues, true)
        ||
        in_array($jobStatus, $submittedStatusValues, true)
        ||
        trim((string)($assignment['final_status'] ?? '')) !== '';

    if ($isSubmitted) {
        $submittedAssignmentRows[] = $assignment;
    } else {
        $activeAssignmentRows[] = $assignment;
    }
}

$totalAssigned =
    count($activeAssignmentRows);

$submittedChecklists =
    count($submittedAssignmentRows);

$pendingChecklists = 0;
$completedServices = 0;
$ownerApproved     = 0;
$ownerReturned     = 0;

/* ---------------------------------------------------------
   ACTIVE / PENDING COUNTERS
--------------------------------------------------------- */

foreach ($activeAssignmentRows as $assignment) {

    $assignmentStatus = strtolower(
        trim(
            (string)($assignment['assignment_status'] ?? '')
        )
    );

    $jobStatus = strtolower(
        trim(
            (string)($assignment['job_status'] ?? '')
        )
    );

    if (
        in_array(
            $assignmentStatus,
            [
                'assigned',
                'pending',
                'in_progress',
                'inspection_pending'
            ],
            true
        )
        ||
        in_array(
            $jobStatus,
            [
                'assigned',
                'pending',
                'in_progress',
                'inspection_pending'
            ],
            true
        )
    ) {
        $pendingChecklists++;
    }
}

/* ---------------------------------------------------------
   SUBMITTED / COMPLETED COUNTERS
--------------------------------------------------------- */

foreach ($submittedAssignmentRows as $assignment) {

    $assignmentStatus = strtolower(
        trim(
            (string)($assignment['assignment_status'] ?? '')
        )
    );

    $jobStatus = strtolower(
        trim(
            (string)($assignment['job_status'] ?? '')
        )
    );

    $ncCount = (int)(
        $assignment['nc_count'] ?? 0
    );

    if (
        in_array(
            $assignmentStatus,
            [
                'completed',
                'submitted',
                'approved',
                'closed'
            ],
            true
        )
        ||
        in_array(
            $jobStatus,
            [
                'approved',
                'closed',
                'completed'
            ],
            true
        )
    ) {
        $completedServices++;
    }

    if (
        $jobStatus === 'approved'
        &&
        $ncCount === 0
    ) {
        $ownerApproved++;
    }

    if (
        in_array(
            $jobStatus,
            [
                'rejected',
                'returned'
            ],
            true
        )
        ||
        in_array(
            $assignmentStatus,
            [
                'rejected',
                'returned'
            ],
            true
        )
    ) {
        $ownerReturned++;
    }
}

/* Keep the complete list available if another dashboard
 * component needs it later. */
$totalAllAssignments = count($assignmentRows);

/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');

/* =========================================================
   INSPECTOR SIDEBAR
========================================================= */

if (
    file_exists(
        __DIR__ .
        '/../includes/sidebar-inspector.php'
    )
) {

    include(
        '../includes/sidebar-inspector.php'
    );
}

?>

<style>
/* =========================================================
   INSPECTOR CONTENT
========================================================= */

.content {
    margin-left: 240px;
    margin-top: 70px;
    padding: 28px 30px 35px;
    width: calc(100% - 240px);
    box-sizing: border-box;
}

/* =========================================================
   DASHBOARD HEADER
========================================================= */

.inspector-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 25px;
}

.inspector-dashboard-title {
    margin: 0;
    font-size: 25px;
    font-weight: 700;
}

.inspector-dashboard-subtitle {
    color: #6c757d;
    font-size: 14px;
    margin-top: 4px;
}

/* =========================================================
   SUMMARY CARDS
========================================================= */

.inspector-summary-card {
    background: #ffffff;
    border: none;
    border-radius: 8px;
    padding: 14px 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    text-align: center;
    height: 100%;
    min-height: 86px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.inspector-summary-card h2 {
    margin: 0;
    font-size: 24px;
    line-height: 1;
    font-weight: 700;
}

.inspector-summary-card p {
    margin: 6px 0 0;
    font-size: 12px;
    line-height: 16px;
    font-weight: 600;
}

/* =========================================================
   MAIN CARD
========================================================= */

.inspector-card {
    background: #ffffff;
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    overflow: hidden;
}

.inspector-card-header {
    padding: 18px 20px;
    border-bottom: 1px solid #eeeeee;
}

.inspector-card-header h5 {
    margin: 0;
    font-weight: 600;
}

.inspector-card-header small {
    color: #6c757d;
}

/* =========================================================
   TABLE
========================================================= */

.inspector-card .table-responsive {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

.inspector-table {
    width: 100%;
    min-width: 860px;
    margin-bottom: 0;
}

.inspector-table th {
    background: #212529;
    color: #ffffff;
    font-size: 13px;
    white-space: nowrap;
}

.inspector-table td {
    vertical-align: middle;
    font-size: 14px;
}

.asset-name {
    font-weight: 600;
}

.asset-id {
    color: #6c757d;
    font-size: 12px;
}

.inspector-action-cell {
    white-space: nowrap;
    min-width: 105px;
}

.inspector-action-cell .btn {
    white-space: nowrap;
}

/* =========================================================
   EMPTY STATE
========================================================= */

.inspector-empty {
    text-align: center;
    padding: 50px 20px;
}

.inspector-empty i {
    font-size: 45px;
    color: #adb5bd;
}

.inspector-empty h5 {
    margin-top: 15px;
    font-weight: 600;
}

.inspector-empty p {
    color: #6c757d;
    max-width: 600px;
    margin: 8px auto 0;
}

/* =========================================================
   WORKFLOW BOX
========================================================= */

.inspector-workflow-box {
    background: #ffffff;
    border: none;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
    padding: 20px;
    height: 100%;
}

.inspector-workflow-number {
    width: 35px;
    height: 35px;
    border-radius: 50%;
    background: #212529;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    margin-bottom: 10px;
}

.inspector-workflow-box h6 {
    font-weight: 600;
}

.inspector-workflow-box p {
    color: #6c757d;
    font-size: 13px;
    margin-bottom: 0;
}

/* =========================================================
   COMPACT SUMMARY GRID
========================================================= */

.content > .row.g-3.mb-4 {
    --bs-gutter-y: 10px;
    --bs-gutter-x: 10px;
    display: grid !important;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px !important;
    margin-left: 0 !important;
    margin-right: 0 !important;
}

.content > .row.g-3.mb-4 > [class*="col-"] {
    width: auto !important;
    max-width: none !important;
    flex: none !important;
    padding-left: 0 !important;
    padding-right: 0 !important;
}

/* =========================================================
   MOBILE / TABLET
========================================================= */

@media (max-width: 991px) {

    .content {
        margin-left: 0;
        margin-top: 56px;
        width: 100%;
        padding: 16px;
    }

    .inspector-dashboard-header {
        margin-bottom: 14px;
        gap: 8px;
    }

    .inspector-dashboard-title {
        font-size: 22px;
    }

    .inspector-dashboard-subtitle {
        font-size: 12px;
    }

    .inspector-summary-card {
        padding: 12px 8px;
        min-height: 78px;
        border-radius: 7px;
    }

    .inspector-summary-card h2 {
        font-size: 22px;
    }

    .inspector-summary-card p {
        font-size: 11px;
        line-height: 14px;
        margin-top: 5px;
    }

    .inspector-table th,
    .inspector-table td {
        padding: 9px 8px;
        font-size: 12px;
    }
}

@media (max-width: 575px) {

    .content {
        margin-top: 56px;
        padding: 10px;
    }

    .inspector-dashboard-header {
        margin-bottom: 10px;
    }

    .inspector-dashboard-title {
        font-size: 19px;
    }

    .inspector-dashboard-subtitle {
        font-size: 10px;
        margin-top: 2px;
    }

    .content > .row.g-3.mb-4 {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px !important;
        margin-bottom: 12px !important;
    }

    .inspector-summary-card {
        min-height: 68px;
        padding: 9px 5px;
        box-shadow: 0 1px 5px rgba(0,0,0,.07);
    }

    .inspector-summary-card h2 {
        font-size: 19px;
    }

    .inspector-summary-card p {
        font-size: 10px;
        line-height: 12px;
        margin-top: 4px;
    }

    .inspector-card {
        border-radius: 7px;
    }

    .inspector-card-header {
        padding: 12px;
    }

    .inspector-card-header h5 {
        font-size: 15px;
    }

    .inspector-card-header small {
        font-size: 11px;
    }

    /*
     * Mobile table becomes card layout.
     */
    .inspector-card .table-responsive {
        overflow: visible;
    }

    .inspector-table,
    .inspector-table tbody,
    .inspector-table tr,
    .inspector-table td {
        display: block;
        width: 100%;
        box-sizing: border-box;
    }

    .inspector-table {
        min-width: 0;
        border-collapse: separate;
        border-spacing: 0;
    }

    .inspector-table thead {
        display: none;
    }

    .inspector-table tbody {
        padding: 8px;
    }

    .inspector-table tr {
        background: #fff;
        border: 1px solid #e6e9ed;
        border-radius: 8px;
        margin-bottom: 10px;
        overflow: hidden;
        box-shadow: 0 1px 4px rgba(0,0,0,.06);
    }

    .inspector-table td {
        min-height: 38px;
        padding: 8px 10px 8px 105px;
        position: relative;
        border: 0;
        border-bottom: 1px solid #f0f1f2;
        font-size: 12px;
        line-height: 17px;
        overflow-wrap: anywhere;
    }

    .inspector-table td:last-child {
        border-bottom: 0;
    }

    .inspector-table td::before {
        content: attr(data-label);
        position: absolute;
        left: 10px;
        top: 8px;
        width: 88px;
        font-weight: 700;
        color: #495057;
        font-size: 11px;
    }

    .inspector-table td.inspector-action-cell {
        padding: 9px 10px;
        text-align: left !important;
        background: #f8f9fa;
        border-bottom: 1px solid #e1e4e7;
    }

    .inspector-table td.inspector-action-cell::before {
        position: static;
        display: inline-block;
        width: 70px;
        margin-right: 8px;
        vertical-align: middle;
    }

    .inspector-table td.inspector-action-cell .btn {
        display: inline-block;
        vertical-align: middle;
    }

    .inspector-table .asset-name {
        font-size: 12px;
    }

    .inspector-table .asset-id {
        font-size: 11px;
        line-height: 16px;
    }

    .inspector-table .badge {
        font-size: 10px;
    }

    .inspector-workflow-box {
        padding: 14px;
    }
}

@media (max-width: 360px) {

    .content {
        padding: 8px;
    }

    .inspector-summary-card {
        min-height: 64px;
        padding: 8px 4px;
    }

    .inspector-summary-card h2 {
        font-size: 18px;
    }

    .inspector-summary-card p {
        font-size: 9px;
        line-height: 11px;
    }
}
</style>

<!-- =======================================================
     MAIN CONTENT
======================================================= -->

<div class="content">

    <!-- HEADER -->

    <div class="inspector-dashboard-header">

        <div>

            <h3 class="inspector-dashboard-title">
                Inspector Dashboard
            </h3>

            <div class="inspector-dashboard-subtitle">
                Inspection and checklist workspace
            </div>

        </div>

    </div>

    <!-- ===================================================
         SUMMARY
    ==================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">

            <div class="inspector-summary-card">

                <h2>
                    <?= $totalAssigned ?>
                </h2>

                <p class="text-primary">
                    Assigned Inspections
                </p>

            </div>

        </div>

        <div class="col-6 col-md-3">

            <div class="inspector-summary-card">

                <h2>
                    <?= $pendingChecklists ?>
                </h2>

                <p class="text-warning">
                    Pending Checklists
                </p>

            </div>

        </div>

        <div class="col-6 col-md-3">

            <div class="inspector-summary-card">

                <h2>
                    <?= $submittedChecklists ?>
                </h2>

                <p class="text-info">
                    Submitted Checklists
                </p>

            </div>

        </div>

        <div class="col-6 col-md-3">

            <div class="inspector-summary-card">

                <h2>
                    <?= $completedServices ?>
                </h2>

                <p class="text-success">
                    Completed Services
                </p>

            </div>

        </div>

    </div>

    <!-- ===================================================
         ASSIGNED INSPECTIONS
    ==================================================== -->

    <div class="inspector-card mb-4">

        <div
            class="
                inspector-card-header
                d-flex
                justify-content-between
                align-items-center
                flex-wrap
                gap-2
            "
        >

            <div>

                <h5>
                    Assigned Inspections
                </h5>

                <small>
                    Inspections waiting for you to start or complete
                </small>

            </div>

            <span class="badge bg-primary">

                <?= $totalAssigned ?>

                Assignment<?=

                    $totalAssigned === 1
                        ? ''
                        : 's'

                ?>

            </span>

        </div>

        <?php if (empty($activeAssignmentRows)): ?>

            <div class="inspector-empty">

                <i class="bi bi-clipboard-x"></i>

                <h5>
                    No Inspection Assigned
                </h5>

                <p>
                    No inspection has been assigned
                    to you yet. Once an inspection is
                    assigned, it will appear here.
                </p>

            </div>

        <?php else: ?>

            <div class="table-responsive">

                <table class="table table-hover inspector-table">

                    <thead>

                        <tr>

                            <th class="text-center">
                                Action
                            </th>

                            <th>
                                Owner
                            </th>

                            <th>
                                Asset
                            </th>

                            <th>
                                Service
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                NC
                            </th>

                            <th>
                                Inspection Date
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach (
                        $activeAssignmentRows
                        as $assignment
                    ): ?>

                        <?php

                        $assignmentId =
                            (int)(
                                $assignment[
                                    'assignment_id'
                                ]
                                ?? 0
                            );

                        $ownerName =
                            $assignment[
                                'owner_name'
                            ]
                            ?? '—';

                        $assetName =
                            $assignment[
                                'asset_name'
                            ]
                            ?? '—';

                        $displayAssetId =
                            $assignment[
                                'display_asset_id'
                            ]
                            ?? '';

                        $location =
                            $assignment[
                                'location'
                            ]
                            ?? '';

                        $serviceId =
                            $assignment[
                                'service_id'
                            ]
                            ?? '';

                        $status =
                            trim(
                                (string)(
                                    $assignment[
                                        'assignment_status'
                                    ]
                                    ?? 'assigned'
                                )
                            );

                        $statusLower =
                            strtolower(
                                $status
                            );

                        if (
                            in_array(
                                $statusLower,
                                [
                                    'completed',
                                    'submitted',
                                    'approved',
                                    'closed'
                                ],
                                true
                            )
                        ) {

                            $statusBadge =
                                'bg-success';

                        } elseif (
                            in_array(
                                $statusLower,
                                [
                                    'in_progress',
                                    'inspection_pending'
                                ],
                                true
                            )
                        ) {

                            $statusBadge =
                                'bg-warning text-dark';

                        } elseif (
                            in_array(
                                $statusLower,
                                [
                                    'rejected',
                                    'cancelled'
                                ],
                                true
                            )
                        ) {

                            $statusBadge =
                                'bg-danger';

                        } else {

                            $statusBadge =
                                'bg-primary';
                        }

                        $inspectionDate = '—';

                        if (
                            !empty(
                                $assignment[
                                    'inspection_date'
                                ]
                            )
                        ) {
                            try {
                                $inspectionDateObj =
                                    new DateTime(
                                        (string)(
                                            $assignment[
                                                'inspection_date'
                                            ]
                                        ),
                                        new DateTimeZone(
                                            'Asia/Kolkata'
                                        )
                                    );

                                $inspectionDate =
                                    $inspectionDateObj
                                    ->format('d M Y');
                            } catch (Exception $e) {
                                $inspectionDate = '—';
                            }
                        }

                        /*
                         * Build the inspection URL as one clean string.
                         *
                         * IMPORTANT:
                         * Do not place whitespace/newlines inside the href
                         * attribute. That can produce an invalid URL on some
                         * deployed servers and result in 404/403 errors.
                         *
                         * inspections.php is the Inspector inspection page
                         * in this same /inspector/ directory.
                         */
                        $inspectionQuery = [
                            'assignment_id' => $assignmentId
                        ];

                        if (
                            !empty(
                                $assignment['job_id']
                            )
                        ) {
                            $inspectionQuery['job_id'] =
                                (int)$assignment['job_id'];
                        }

                        if (
                            !empty(
                                $assignment['asset_id']
                            )
                        ) {
                            $inspectionQuery['asset_id'] =
                                (int)$assignment['asset_id'];
                        }

                        $inspectionUrl =
                            './inspections.php?'
                            . http_build_query(
                                $inspectionQuery,
                                '',
                                '&',
                                PHP_QUERY_RFC3986
                            );

                        ?>

                        <tr>

                            <!-- ACTION -->

                            <td
                                class="
                                    inspector-action-cell
                                    text-center
                                "
                                data-label="Action"
                            >

                                <?php if (
                                    in_array(
                                        $statusLower,
                                        [
                                            'completed',
                                            'submitted',
                                            'approved',
                                            'closed'
                                        ],
                                        true
                                    )
                                ): ?>

                                    <a
                                        href="<?= h($inspectionUrl) ?>"
                                        class="
                                            btn
                                            btn-sm
                                            btn-outline-primary
                                        "
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-eye
                                            "
                                        ></i>

                                        View

                                    </a>

                                <?php else: ?>

                                    <a
                                        href="<?= h($inspectionUrl) ?>"
                                        class="
                                            btn
                                            btn-sm
                                            btn-primary
                                        "
                                    >

                                        <i
                                            class="
                                                bi
                                                bi-clipboard-check
                                            "
                                        ></i>

                                        Checklist

                                    </a>

                                <?php endif; ?>

                            </td>

                            <!-- OWNER -->

                            <td data-label="Owner">

                                <strong>
                                    <?= h($ownerName) ?>
                                </strong>

                            </td>

                            <!-- ASSET -->

                            <td data-label="Asset">

                                <div class="asset-name">
                                    <?= h($assetName) ?>
                                </div>

                                <?php if (
                                    !empty(
                                        $displayAssetId
                                    )
                                ): ?>

                                    <div class="asset-id">

                                        ID:
                                        <?= h(
                                            $displayAssetId
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                                <?php if (
                                    !empty(
                                        $location
                                    )
                                ): ?>

                                    <div class="asset-id">

                                        <?= h(
                                            $location
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                            </td>

                            <!-- SERVICE -->

                            <td data-label="Service">

                                <?php
                                $serviceName =
                                    trim(
                                        (string)(
                                            $assignment[
                                                'service_name'
                                            ] ?? ''
                                        )
                                    );
                                ?>

                                <?php if (
                                    $serviceName !== ''
                                ): ?>

                                    <strong>
                                        <?= h(
                                            $serviceName
                                        ) ?>
                                    </strong>

                                <?php elseif (
                                    $serviceId !== ''
                                    &&
                                    $serviceId !== null
                                ): ?>

                                    <span class="text-muted">

                                        Service #
                                        <?= h(
                                            $serviceId
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span class="text-muted">
                                        Service pending
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- STATUS -->

                            <td data-label="Status">

                                <span
                                    class="
                                        badge
                                        <?= h(
                                            $statusBadge
                                        ) ?>
                                    "
                                >

                                    <?= h(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $status
                                            )
                                        )
                                    ) ?>

                                </span>

                            </td>

                            <!-- NC ONLY -->

                            <td data-label="NC">

                                <?php if (
                                    (int)(
                                        $assignment[
                                            'nc_count'
                                        ]
                                        ?? 0
                                    ) > 0
                                ): ?>

                                    <span class="badge bg-danger">

                                        <?= (int)(
                                            $assignment[
                                                'nc_count'
                                            ]
                                        ) ?>

                                        NC

                                    </span>

                                <?php elseif (
                                    strtolower(
                                        (string)(
                                            $assignment[
                                                'job_status'
                                            ]
                                            ?? ''
                                        )
                                    ) === 'approved'
                                ): ?>

                                    <span class="badge bg-success">
                                        No NC
                                    </span>

                                <?php else: ?>

                                    <span class="text-muted small">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>

                            <!-- ASSIGNED DATE -->

                            <td data-label="Inspection Date">

                                <?= h(
                                    $inspectionDate
                                ) ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

    <!-- ===================================================
         INSPECTOR WORKFLOW
    ==================================================== -->

    <h5 class="mb-3">
        Inspection Workflow
    </h5>

    <div class="row g-3 mb-4">

        <!-- STEP 1 -->

        <div class="col-12 col-md-4">

            <div class="inspector-workflow-box">

                <div class="inspector-workflow-number">
                    1
                </div>

                <h6>
                    Assigned Inspection
                </h6>

                <p>
                    View the assets and services
                    assigned to you by NLETA.
                </p>

            </div>

        </div>

        <!-- STEP 2 -->

        <div class="col-12 col-md-4">

            <div class="inspector-workflow-box">

                <div class="inspector-workflow-number">
                    2
                </div>

                <h6>
                    Complete Checklist
                </h6>

                <p>
                    Open the assigned inspection
                    and complete the applicable
                    digital checklist.
                </p>

            </div>

        </div>

        <!-- STEP 3 -->

        <div class="col-12 col-md-4">

            <div class="inspector-workflow-box">

                <div class="inspector-workflow-number">
                    3
                </div>

                <h6>
                    Submit Inspection
                </h6>

                <p>
                    Submit the completed checklist
                    with the available inspection evidence.
                </p>

            </div>

        </div>

    </div>

    <!-- ROLE INFORMATION -->

    <div class="alert alert-info shadow-sm">

        <strong>
            Inspector Access:
        </strong>

        You can access only inspection-related
        assignments, checklists and inspection history.

    </div>

</div>

<?php

/* =========================================================
   FOOTER
========================================================= */

if (
    file_exists(
        __DIR__ .
        '/../includes/footer.php'
    )
) {

    include(
        '../includes/footer.php'
    );
}

?>
