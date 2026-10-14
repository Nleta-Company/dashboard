<?php
/* =========================================================
   NLETA INSPECTOR — HISTORY
   ---------------------------------------------------------
   Shows the logged-in Inspector's inspection history.

   Includes:
   - Inspection date/time
   - Lift / Asset
   - Owner
   - PSGA / SAF
   - Inspection status
   - Recheck status/date
   - Completion time
   - Certificate status

   Database timestamps are displayed in IST.
========================================================= */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

require_role('inspector');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   HELPERS
========================================================= */

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


/*
 * DB timestamps are treated as UTC and displayed as IST.
 */
function formatIST($datetime, string $format = 'd / m / Y h:i A'): string
{
    if (empty($datetime)) {
        return '—';
    }

    try {
        $date = new DateTime(
            (string)$datetime,
            new DateTimeZone('UTC')
        );

        $date->setTimezone(
            new DateTimeZone('Asia/Kolkata')
        );

        return $date->format($format);

    } catch (Throwable $e) {
        return '—';
    }
}


/* =========================================================
   CURRENT INSPECTOR
========================================================= */

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
   LOAD INSPECTION HISTORY
   ---------------------------------------------------------
   inspector_assignments is the source of Inspector ownership.
   Do NOT use maintenance_jobs.inspector_id.
========================================================= */

$history = [];

$stmt = $conn->prepare("
    SELECT
        ia.id AS assignment_id,
        ia.job_id,
        ia.asset_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,
        ia.submitted_at,
        ia.completed_at,
        ia.remarks AS assignment_remarks,

        mj.status AS job_status,
        mj.job_type,
        mj.created_at AS job_created_at,
        mj.updated_at AS job_updated_at,
        mj.recheck_date,
        mj.recheck_status,
        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        owner.name AS owner_name,

        a.asset_name,
        a.display_asset_id,
        a.location,

        s.psga_no,
        s.saf_no,
        s.service_name

    FROM inspector_assignments ia

    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id

    LEFT JOIN users owner
        ON owner.id = ia.owner_id

    LEFT JOIN assets a
        ON a.id = ia.asset_id

    LEFT JOIN saf_contracts s
        ON s.id = ia.job_id
        AND 1 = 0

    WHERE
        ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    ORDER BY
        COALESCE(
            ia.completed_at,
            ia.submitted_at,
            ia.started_at,
            ia.assigned_at,
            mj.created_at
        ) DESC
");

if (!$stmt) {
    die(
        'Unable to load Inspector history: '
        . h($conn->error)
    );
}

/*
 * The SAF contract relationship is through maintenance_jobs.saf_id,
 * so use a corrected query if the statement above is not suitable.
 */
$stmt->close();

$stmt = $conn->prepare("
    SELECT
        ia.id AS assignment_id,
        ia.job_id,
        ia.asset_id,
        ia.status AS assignment_status,
        ia.assigned_at,
        ia.started_at,
        ia.submitted_at,
        ia.completed_at,
        ia.remarks AS assignment_remarks,

        mj.status AS job_status,
        mj.job_type,
        mj.created_at AS job_created_at,
        mj.updated_at AS job_updated_at,
        mj.recheck_date,
        mj.recheck_status,
        mj.certificate_status,
        mj.certificate_no,
        mj.certificate_generated_at,

        owner.name AS owner_name,

        a.asset_name,
        a.display_asset_id,
        a.location,

        s.psga_no,
        s.saf_no,
        s.service_name

    FROM inspector_assignments ia

    INNER JOIN maintenance_jobs mj
        ON mj.id = ia.job_id

    LEFT JOIN users owner
        ON owner.id = ia.owner_id

    LEFT JOIN assets a
        ON a.id = ia.asset_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id

    WHERE
        ia.inspector_id = ?
        AND mj.job_type = 'saf_inspection'

    ORDER BY
        COALESCE(
            ia.completed_at,
            ia.submitted_at,
            ia.started_at,
            ia.assigned_at,
            mj.created_at
        ) DESC
");

if (!$stmt) {
    die(
        'Unable to prepare Inspector history query: '
        . h($conn->error)
    );
}

$stmt->bind_param('i', $inspectorId);

if (!$stmt->execute()) {
    die(
        'Unable to execute Inspector history query: '
        . h($stmt->error)
    );
}

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $history[] = $row;
}

$stmt->close();


/* =========================================================
   SUMMARY
========================================================= */

$totalInspections = count($history);
$completedCount = 0;
$recheckCount = 0;
$certificateCount = 0;

foreach ($history as $item) {

    $assignmentStatus = strtolower(
        trim((string)($item['assignment_status'] ?? ''))
    );

    $jobStatus = strtolower(
        trim((string)($item['job_status'] ?? ''))
    );

    $recheckStatus = strtolower(
        trim((string)($item['recheck_status'] ?? ''))
    );

    $certificateStatus = strtolower(
        trim((string)($item['certificate_status'] ?? ''))
    );

    if (
        in_array(
            $assignmentStatus,
            ['completed', 'submitted'],
            true
        )
        ||
        in_array(
            $jobStatus,
            ['closed', 'completed', 'approved'],
            true
        )
    ) {
        $completedCount++;
    }

    if ($recheckStatus === 'completed') {
        $recheckCount++;
    }

    if ($certificateStatus === 'generated') {
        $certificateCount++;
    }
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-inspector.php');

?>

<style>
.history-page {
    padding: 24px;
    background: #f4f6f9;
    min-height: calc(100vh - 70px);
}

.history-header {
    background: #fff;
    border: 1px solid #e3e7ed;
    border-radius: 14px;
    padding: 22px 24px;
    margin-bottom: 18px;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
}

.history-header h2 {
    margin: 0;
    color: #172033;
    font-weight: 700;
}

.history-header p {
    margin: 6px 0 0;
    color: #6c757d;
    font-size: 14px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 18px;
}

.summary-card {
    background: #fff;
    border: 1px solid #e3e7ed;
    border-radius: 12px;
    padding: 17px 18px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.summary-card .label {
    color: #6c757d;
    font-size: 12px;
    font-weight: 600;
}

.summary-card .value {
    margin-top: 5px;
    color: #172033;
    font-size: 25px;
    font-weight: 700;
}

.history-card {
    background: #fff;
    border: 1px solid #e3e7ed;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
}

.history-card-header {
    padding: 19px 22px;
    border-bottom: 1px solid #e9ecef;
}

.history-card-header h3 {
    margin: 0;
    color: #172033;
    font-size: 20px;
    font-weight: 700;
}

.history-card-header p {
    margin: 5px 0 0;
    color: #6c757d;
    font-size: 13px;
}

.table-wrap {
    overflow-x: auto;
}

.history-table {
    width: 100%;
    min-width: 1250px;
    border-collapse: collapse;
}

.history-table th {
    background: #f6f8fb;
    color: #26364d;
    padding: 12px;
    border-bottom: 1px solid #dee2e6;
    font-size: 12px;
    white-space: nowrap;
}

.history-table td {
    padding: 13px 12px;
    border-bottom: 1px solid #edf0f4;
    font-size: 13px;
    vertical-align: middle;
}

.history-table tbody tr:hover {
    background: #f9fbfd;
}

.asset-name {
    color: #172033;
    font-weight: 700;
}

.meta {
    color: #6c757d;
    font-size: 11px;
    margin-top: 3px;
}

.badge-status {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 15px;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
}

.badge-complete {
    background: #d9f4e5;
    color: #0c6b35;
}

.badge-progress {
    background: #fff1c2;
    color: #7a5200;
}

.badge-recheck {
    background: #e7f1ff;
    color: #0d6efd;
}

.badge-none {
    background: #eef0f3;
    color: #59636f;
}

.badge-certificate {
    background: #efe3ff;
    color: #6f42c1;
}

.empty-history {
    padding: 65px 20px;
    text-align: center;
    color: #6c757d;
}

.empty-history i {
    display: block;
    font-size: 45px;
    margin-bottom: 12px;
    color: #198754;
}

.action-btn {
    border-radius: 7px;
    font-weight: 600;
    white-space: nowrap;
}

.ist-note {
    margin-top: 14px;
    color: #6c757d;
    font-size: 12px;
}

@media (max-width: 900px) {
    .history-page {
        padding: 14px;
    }

    .summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 520px) {
    .summary-grid {
        grid-template-columns: 1fr;
    }
}
</style>


<div class="content">

<div class="history-page">

    <!-- =====================================================
         PAGE HEADER
    ====================================================== -->

    <div class="history-header">

        <h2>
            <i class="bi bi-clock-history me-2"></i>
            Inspection History
        </h2>

        <p>
            Your completed and previous PAT inspection assignments.
        </p>
    </div>


    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <div class="summary-grid">

        <div class="summary-card">
            <div class="label">TOTAL INSPECTIONS</div>
            <div class="value"><?= (int)$totalInspections ?></div>
        </div>

        <div class="summary-card">
            <div class="label">COMPLETED / SUBMITTED</div>
            <div class="value"><?= (int)$completedCount ?></div>
        </div>

        <div class="summary-card">
            <div class="label">RECHECK COMPLETED</div>
            <div class="value"><?= (int)$recheckCount ?></div>
        </div>

        <div class="summary-card">
            <div class="label">CERTIFICATES GENERATED</div>
            <div class="value"><?= (int)$certificateCount ?></div>
        </div>

    </div>


    <!-- =====================================================
         HISTORY TABLE
    ====================================================== -->

    <div class="history-card">

        <div class="history-card-header">

            <h3>
                <i class="bi bi-list-check me-2"></i>
                All Inspection History
            </h3>

            <p>
                Inspection records assigned to the currently logged-in Inspector.
            </p>

        </div>


        <?php if (empty($history)): ?>

            <div class="empty-history">

                <i class="bi bi-clock-history"></i>

                <strong>
                    No Inspection History
                </strong>

                <div class="mt-1">
                    No PAT inspection assignments have been recorded for your account yet.
                </div>

            </div>

        <?php else: ?>

            <div class="table-wrap">

                <table class="history-table">

                    <thead>
                    <tr>
                        <th>#</th>
                        <th>Action</th>
                        <th>Lift / Asset</th>
                        <th>Owner</th>
                        <th>PSGA</th>
                        <th>SAF</th>
                        <th>Inspection Date</th>
                        <th>Assignment</th>
                        <th>Inspection</th>
                        <th>Recheck</th>
                        <th>Completed</th>
                        <th>Certificate</th>
                    </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($history as $index => $row): ?>

                        <?php
                        $assignmentStatus = strtolower(
                            trim((string)($row['assignment_status'] ?? ''))
                        );

                        $jobStatus = strtolower(
                            trim((string)($row['job_status'] ?? ''))
                        );

                        $recheckStatus = strtolower(
                            trim((string)($row['recheck_status'] ?? ''))
                        );

                        $certificateStatus = strtolower(
                            trim((string)($row['certificate_status'] ?? ''))
                        );

                        $inspectionTimestamp =
                            $row['started_at']
                            ?: $row['assigned_at']
                            ?: $row['job_created_at'];

                        $completionTimestamp =
                            $row['completed_at']
                            ?: $row['submitted_at']
                            ?: null;
                        ?>

                        <tr>

                            <td>
                                <?= (int)($index + 1) ?>
                            </td>

                            <td>
                                <a
                                    href="inspection-history-view.php?job_id=<?= (int)$row['job_id'] ?>&asset_id=<?= (int)$row['asset_id'] ?>"
                                    class="btn btn-primary btn-sm action-btn"
                                >
                                    <i class="bi bi-eye me-1"></i>
                                    View Checklist
                                </a>
                            </td>

                            <td>
                                <div class="asset-name">
                                    <?= h(
                                        $row['display_asset_id']
                                        ?: $row['asset_name']
                                        ?: '—'
                                    ) ?>
                                </div>

                                <?php if (!empty($row['asset_name'])): ?>
                                    <div class="meta">
                                        <?= h($row['asset_name']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if (!empty($row['location'])): ?>
                                    <div class="meta">
                                        <?= h($row['location']) ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?= h($row['owner_name'] ?: '—') ?>
                            </td>

                            <td>
                                <?= h($row['psga_no'] ?: '—') ?>
                            </td>

                            <td>
                                <?= h($row['saf_no'] ?: '—') ?>
                            </td>

                            <td>
                                <?= h(
                                    formatIST(
                                        $inspectionTimestamp,
                                        'd / m / Y h:i A'
                                    )
                                ) ?>
                            </td>

                            <td>
                                <?php if (
                                    in_array(
                                        $assignmentStatus,
                                        ['completed', 'submitted'],
                                        true
                                    )
                                ): ?>

                                    <span class="badge-status badge-complete">
                                        <?= h(ucwords(str_replace('_', ' ', $assignmentStatus))) ?>
                                    </span>

                                <?php elseif ($assignmentStatus !== ''): ?>

                                    <span class="badge-status badge-progress">
                                        <?= h(ucwords(str_replace('_', ' ', $assignmentStatus))) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="badge-status badge-none">
                                        —
                                    </span>

                                <?php endif; ?>
                            </td>

                            <td>

                                <span class="badge-status
                                    <?=
                                        in_array(
                                            $jobStatus,
                                            ['closed', 'completed', 'approved'],
                                            true
                                        )
                                        ? 'badge-complete'
                                        : 'badge-progress'
                                    ?>
                                ">
                                    <?= h(
                                        $jobStatus
                                        ? ucwords(str_replace('_', ' ', $jobStatus))
                                        : '—'
                                    ) ?>
                                </span>

                            </td>

                            <td>

                                <?php if ($recheckStatus === 'completed'): ?>

                                    <span class="badge-status badge-recheck">
                                        Completed
                                    </span>

                                    <?php if (!empty($row['recheck_date'])): ?>
                                        <div class="meta">
                                            <?= h(
                                                formatIST(
                                                    $row['recheck_date'],
                                                    'd / m / Y'
                                                )
                                            ) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php elseif (
                                    $recheckStatus === 'scheduled'
                                ): ?>

                                    <span class="badge-status badge-progress">
                                        Scheduled
                                    </span>

                                    <?php if (!empty($row['recheck_date'])): ?>
                                        <div class="meta">
                                            <?= h(
                                                formatIST(
                                                    $row['recheck_date'],
                                                    'd / m / Y'
                                                )
                                            ) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php else: ?>

                                    <span class="badge-status badge-none">
                                        Not Required
                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>
                                <?= h(
                                    formatIST(
                                        $completionTimestamp,
                                        'd / m / Y h:i A'
                                    )
                                ) ?>
                            </td>

                            <td>

                                <?php if ($certificateStatus === 'generated'): ?>

                                    <span class="badge-status badge-certificate">
                                        Generated
                                    </span>

                                    <?php if (!empty($row['certificate_no'])): ?>
                                        <div class="meta">
                                            <?= h($row['certificate_no']) ?>
                                        </div>
                                    <?php endif; ?>

                                <?php elseif ($certificateStatus !== ''): ?>

                                    <span class="badge-status badge-progress">
                                        <?= h(
                                            ucwords(
                                                str_replace(
                                                    '_',
                                                    ' ',
                                                    $certificateStatus
                                                )
                                            )
                                        ) ?>
                                    </span>

                                <?php else: ?>

                                    <span class="badge-status badge-none">
                                        —
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

</div>


<?php
include('../includes/footer.php');
?>
