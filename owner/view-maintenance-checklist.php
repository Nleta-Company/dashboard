<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');

date_default_timezone_set('Asia/Kolkata');

$owner_id = (int)($_SESSION['uid'] ?? 0);

$job_id = (int)(
    $_POST['job_id']
    ?? $_GET['job_id']
    ?? 0
);

if (
    $owner_id <= 0 ||
    $job_id <= 0
) {

    $_SESSION['error'] =
        'Invalid maintenance checklist request.';

    header(
        'Location: maintenance-checklist.php'
    );

    exit;
}


/* =========================================================
   POST ACTION
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCSRFToken(
        $_POST['csrf_token'] ?? ''
    )) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            'Location: view-maintenance-checklist.php?job_id=' .
            $job_id
        );

        exit;
    }


    $action =
        trim(
            (string)(
                $_POST['action'] ?? ''
            )
        );


    if (!in_array(
        $action,
        [
            'approve',
            'reject'
        ],
        true
    )) {

        $_SESSION['error'] =
            'Invalid maintenance action.';

        header(
            'Location: view-maintenance-checklist.php?job_id=' .
            $job_id
        );

        exit;
    }


    /* =====================================================
       LOAD + LOCK JOB
    ===================================================== */

    $jobStmt = $conn->prepare("
        SELECT
            mj.id,
            mj.asset_id,
            mj.status,
            mj.technician_id,
            mj.job_type,
            a.owner_id,
            a.maintenance_interval
        FROM maintenance_jobs mj
        INNER JOIN assets a
            ON a.id = mj.asset_id
        WHERE
            mj.id = ?
            AND a.owner_id = ?
            AND LOWER(TRIM(COALESCE(mj.job_type, '')))
                NOT IN ('fortnightly', 'saf_inspection')
        LIMIT 1
        FOR UPDATE
    ");

    /*
     * FOR UPDATE requires transaction.
     */
    $conn->begin_transaction();

    try {

        $jobStmt->bind_param(
            "ii",
            $job_id,
            $owner_id
        );

        $jobStmt->execute();

        $lockedJob =
            $jobStmt
            ->get_result()
            ->fetch_assoc();

        $jobStmt->close();


        if (!$lockedJob) {

            throw new Exception(
                'Maintenance checklist not found.'
            );
        }


        if (
            $lockedJob['status']
            !== 'pending_owner'
        ) {

            throw new Exception(
                'This checklist is no longer pending owner approval.'
            );
        }


        /* =================================================
           APPROVE
        ================================================= */

        if ($action === 'approve') {

            $today =
                date('Y-m-d');


            /* ---------------------------------------------
               JOB → APPROVED
            --------------------------------------------- */

            $updJob = $conn->prepare("
                UPDATE maintenance_jobs
                SET
                    status = 'approved'
                WHERE
                    id = ?
                    AND status = 'pending_owner'
            ");

            $updJob->bind_param(
                "i",
                $job_id
            );

            if (
                !$updJob->execute() ||
                $updJob->affected_rows !== 1
            ) {

                throw new Exception(
                    'Unable to approve maintenance checklist.'
                );
            }

            $updJob->close();


            /* ---------------------------------------------
               MAINTENANCE RECORD
            --------------------------------------------- */

            $checkRec = $conn->prepare("
                SELECT id
                FROM maintenance_records
                WHERE
                    asset_id = ?
                    AND maintenance_date = ?
                LIMIT 1
            ");

            $checkRec->bind_param(
                "is",
                $lockedJob['asset_id'],
                $today
            );

            $checkRec->execute();

            $existingRecord =
                $checkRec
                ->get_result()
                ->fetch_assoc();

            $checkRec->close();


            if ($existingRecord) {

                $updRec = $conn->prepare("
                    UPDATE maintenance_records
                    SET
                        status = 'approved'
                    WHERE id = ?
                ");

                $updRec->bind_param(
                    "i",
                    $existingRecord['id']
                );

                if (!$updRec->execute()) {

                    throw new Exception(
                        'Unable to update maintenance record.'
                    );
                }

                $updRec->close();

            } else {

                $insRec = $conn->prepare("
                    INSERT INTO maintenance_records
                    (
                        asset_id,
                        maintenance_date,
                        remarks,
                        status
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        '',
                        'approved'
                    )
                ");

                $insRec->bind_param(
                    "is",
                    $lockedJob['asset_id'],
                    $today
                );

                if (!$insRec->execute()) {

                    throw new Exception(
                        'Unable to create maintenance record.'
                    );
                }

                $insRec->close();
            }


            /* ---------------------------------------------
               RESET ASSET MAINTENANCE CYCLE
            --------------------------------------------- */

            $updAsset = $conn->prepare("
                UPDATE assets
                SET
                    maintenance_start_date = ?,
                    status = 'Active',
                    updated_at = CURDATE()
                WHERE
                    id = ?
                    AND owner_id = ?
            ");

            $updAsset->bind_param(
                "sii",
                $today,
                $lockedJob['asset_id'],
                $owner_id
            );

            if (!$updAsset->execute()) {

                throw new Exception(
                    'Unable to update asset maintenance date.'
                );
            }

            $updAsset->close();


            /* ---------------------------------------------
               COMMIT
            --------------------------------------------- */

            $conn->commit();


            $_SESSION['success'] =
                'Maintenance checklist approved. '
                . 'Today is now the new maintenance date.';

            header(
                'Location: maintenance-checklist.php'
            );

            exit;
        }


        /* =================================================
           REJECT
        ================================================= */

        if ($action === 'reject') {

            $upd = $conn->prepare("
                UPDATE maintenance_jobs
                SET
                    status = 'rejected'
                WHERE
                    id = ?
                    AND status = 'pending_owner'
            ");

            $upd->bind_param(
                "i",
                $job_id
            );

            if (
                !$upd->execute() ||
                $upd->affected_rows !== 1
            ) {

                throw new Exception(
                    'Unable to reject maintenance checklist.'
                );
            }

            $upd->close();


            /*
             * DO NOT change maintenance_start_date.
             *
             * Technician will rework this same cycle.
             */

            $conn->commit();


            $_SESSION['success'] =
                'Maintenance checklist rejected and sent for rework.';

            header(
                'Location: maintenance-checklist.php'
            );

            exit;
        }


    } catch (Throwable $e) {

        $conn->rollback();

        error_log(
            'Maintenance checklist action error: ' .
            $e->getMessage()
        );

        $_SESSION['error'] =
            $e->getMessage();

        header(
            'Location: view-maintenance-checklist.php?job_id=' .
            $job_id
        );

        exit;
    }
}


/* =========================================================
   LOAD CHECKLIST
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.checklist,
        mj.remarks,
        mj.file,
        mj.created_at,
        mj.status,
        mj.job_type,
        a.asset_name,
        a.location,
        a.state,
        u.name AS technician_name
    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users u
        ON u.id = mj.technician_id

    WHERE
        mj.id = ?
        AND a.owner_id = ?
        AND LOWER(TRIM(COALESCE(mj.job_type, '')))
            NOT IN ('fortnightly', 'saf_inspection')

    LIMIT 1
");

$stmt->bind_param(
    'ii',
    $job_id,
    $owner_id
);

$stmt->execute();

$job =
    $stmt
    ->get_result()
    ->fetch_assoc();


if (!$job) {

    die(
        'Maintenance checklist not found or it does not belong to your account.'
    );
}


/* =========================================================
   CHECKLIST DATA
========================================================= */

$decodedChecklist = [];

if (!empty($job['checklist'])) {

    $decodedChecklist =
        json_decode(
            $job['checklist'],
            true
        );

    if (!is_array(
        $decodedChecklist
    )) {

        $decodedChecklist = [];
    }
}


$checklistItems = [];


if (
    isset(
        $decodedChecklist['results']
    )
    &&
    is_array(
        $decodedChecklist['results']
    )
) {

    foreach (
        $decodedChecklist['results']
        as $ref => $item
    ) {

        if (is_array($item)) {

            $label = trim(
                (string)(
                    $item['label']
                    ??
                    $item['question']
                    ??
                    $item['checkpoint']
                    ??
                    $ref
                )
            );

            $result = trim(
                (string)(
                    $item['result']
                    ??
                    $item['status']
                    ??
                    ''
                )
            );

        } else {

            $label =
                (string)$ref;

            $result =
                (string)$item;
        }


        $checklistItems[] = [
            'label'  => $label,
            'result' => $result
        ];
    }


} elseif (
    function_exists('array_is_list') &&
    array_is_list($decodedChecklist)
) {

    foreach (
        $decodedChecklist
        as $item
    ) {

        if (is_scalar($item)) {

            $checklistItems[] = [
                'label' =>
                    (string)$item,

                'result' =>
                    'Completed'
            ];

        } elseif (
            is_array($item)
        ) {

            $checklistItems[] = [

                'label' =>
                    (string)(
                        $item['label']
                        ??
                        $item['question']
                        ??
                        $item['checkpoint']
                        ??
                        'Checklist Item'
                    ),

                'result' =>
                    (string)(
                        $item['result']
                        ??
                        $item['status']
                        ??
                        'Completed'
                    )
            ];
        }
    }
}


/* =========================================================
   FILE
========================================================= */

$legacyFileUrl = '';

if (!empty($job['file'])) {

    $legacyFile =
        basename(
            (string)$job['file']
        );

    $legacyFilePath =
        __DIR__ .
        '/../uploads/' .
        $legacyFile;

    if (
        file_exists(
            $legacyFilePath
        )
    ) {

        $legacyFileUrl =
            '../uploads/' .
            rawurlencode(
                $legacyFile
            );
    }
}


/* =========================================================
   DATE
========================================================= */

function maintenanceViewDateIST(
    ?string $value
): string {

    if (empty($value)) {
        return '—';
    }

    try {

        $dt = new DateTime(
            $value,
            new DateTimeZone('UTC')
        );

        $dt->setTimezone(
            new DateTimeZone(
                'Asia/Kolkata'
            )
        );

        return htmlspecialchars(
            $dt->format(
                'd M Y h:i A'
            ) . ' IST',
            ENT_QUOTES,
            'UTF-8'
        );

    } catch (Exception $e) {

        return htmlspecialchars(
            $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}


include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

<div
    class="container-fluid"
    style="max-width:1000px"
>

    <div class="mb-3">

        <a
            href="maintenance-checklist.php"
            class="btn btn-outline-secondary btn-sm"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Back to Maintenance Checklist
        </a>

    </div>


    <?php if (!empty($_SESSION['success'])): ?>

        <div class="alert alert-success">

            <?= htmlspecialchars(
                $_SESSION['success']
            ) ?>

            <?php unset(
                $_SESSION['success']
            ); ?>

        </div>

    <?php endif; ?>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger">

            <?= htmlspecialchars(
                $_SESSION['error']
            ) ?>

            <?php unset(
                $_SESSION['error']
            ); ?>

        </div>

    <?php endif; ?>


    <div class="card shadow">

        <div class="card-header">

            <div class="d-flex justify-content-between align-items-center">

                <div>

                    <h4 class="mb-1">
                        <i class="bi bi-clipboard2-check me-2"></i>
                        Maintenance Checklist
                    </h4>

                    <small class="text-muted">
                        Technician submitted maintenance report
                    </small>

                </div>


                <div>

                    <?php

                    if (
                        $job['status']
                        === 'pending_owner'
                    ) {

                        echo '
                        <span class="badge bg-warning text-dark">
                            Pending Approval
                        </span>';

                    } elseif (
                        $job['status']
                        === 'approved'
                    ) {

                        echo '
                        <span class="badge bg-success">
                            Approved
                        </span>';

                    } elseif (
                        $job['status']
                        === 'rejected'
                    ) {

                        echo '
                        <span class="badge bg-danger">
                            Rejected / Rework
                        </span>';

                    } else {

                        echo '
                        <span class="badge bg-secondary">' .
                            htmlspecialchars(
                                ucfirst(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $job['status']
                                    )
                                )
                            ) .
                        '</span>';
                    }

                    ?>

                </div>

            </div>

        </div>


        <div class="card-body">


            <div class="row g-3 mb-4">

                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Lift
                        </small>

                        <div class="fw-bold">
                            <?= htmlspecialchars(
                                $job['asset_name']
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Location
                        </small>

                        <div class="fw-bold">
                            <?= htmlspecialchars(
                                $job['location']
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Technician
                        </small>

                        <div class="fw-bold">
                            <?= htmlspecialchars(
                                $job['technician_name']
                                ?? '—'
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Submitted
                        </small>

                        <div class="fw-bold">
                            <?= maintenanceViewDateIST(
                                $job['created_at']
                            ) ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Job ID
                        </small>

                        <div class="fw-bold">
                            #<?= (int)$job['id'] ?>
                        </div>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3">

                        <small class="text-muted">
                            Job Type
                        </small>

                        <div class="fw-bold">
                            <?= htmlspecialchars(
                                $job['job_type']
                                ?: 'Maintenance'
                            ) ?>
                        </div>

                    </div>

                </div>

            </div>


            <h5 class="mb-3">
                Checklist Items
            </h5>


            <?php if (
                empty($checklistItems)
            ): ?>

                <div class="alert alert-warning">

                    No checklist items were saved
                    with this maintenance report.

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table table-bordered">

                        <thead class="table-light">

                        <tr>

                            <th width="70">
                                #
                            </th>

                            <th>
                                Checklist Item
                            </th>

                            <th width="180">
                                Result
                            </th>

                        </tr>

                        </thead>


                        <tbody>

                        <?php foreach (
                            $checklistItems
                            as $index => $item
                        ): ?>

                            <?php

                            $resultUpper =
                                strtoupper(
                                    trim(
                                        (string)(
                                            $item['result']
                                        )
                                    )
                                );


                            $badgeClass =
                                $resultUpper === 'NC'
                                ? 'bg-danger'
                                :
                                (
                                    in_array(
                                        $resultUpper,
                                        [
                                            'OK',
                                            'COMPLIED',
                                            'COMPLETED',
                                            'YES',
                                            'PASS'
                                        ],
                                        true
                                    )
                                    ? 'bg-success'
                                    : 'bg-secondary'
                                );

                            ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>

                                <td>
                                    <?= nl2br(
                                        htmlspecialchars(
                                            $item['label'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                    ) ?>
                                </td>

                                <td>

                                    <span
                                        class="badge <?= $badgeClass ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $item['result']
                                            ?: 'Completed',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


            <h5 class="mt-4 mb-3">
                Remarks
            </h5>


            <div
                class="border rounded p-3 bg-light"
            >

                <?php if (
                    !empty(
                        trim(
                            $job['remarks']
                            ?? ''
                        )
                    )
                ): ?>

                    <?= nl2br(
                        htmlspecialchars(
                            $job['remarks'],
                            ENT_QUOTES,
                            'UTF-8'
                        )
                    ) ?>

                <?php else: ?>

                    <span class="text-muted">
                        No remarks provided.
                    </span>

                <?php endif; ?>

            </div>


            <?php if (
                $legacyFileUrl !== ''
            ): ?>

                <h5 class="mt-4 mb-3">
                    Attached File
                </h5>

                <a
                    href="<?= htmlspecialchars(
                        $legacyFileUrl,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                    target="_blank"
                    class="btn btn-outline-primary"
                >

                    <i class="bi bi-paperclip me-1"></i>

                    View Attachment

                </a>

            <?php endif; ?>


        </div>


        <?php if (
            $job['status']
            === 'pending_owner'
        ): ?>

            <div class="card-footer">

                <div class="d-flex gap-2 flex-wrap">


                    <form method="POST">

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="job_id"
                            value="<?= (int)$job['id'] ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="approve"
                        >

                        <button
                            type="submit"
                            class="btn btn-success"
                            onclick="
                                return confirm(
                                    'Approve this maintenance checklist?'
                                );
                            "
                        >

                            <i class="bi bi-check-lg me-1"></i>

                            Approve Maintenance

                        </button>

                    </form>


                    <form method="POST">

                        <?= csrfField() ?>

                        <input
                            type="hidden"
                            name="job_id"
                            value="<?= (int)$job['id'] ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="reject"
                        >

                        <button
                            type="submit"
                            class="btn btn-danger"
                            onclick="
                                return confirm(
                                    'Reject this maintenance checklist and send it for rework?'
                                );
                            "
                        >

                            <i class="bi bi-x-lg me-1"></i>

                            Reject Maintenance

                        </button>

                    </form>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

</div>

<?php include('../includes/footer.php'); ?>