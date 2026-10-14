<?php

include('../config/db.php');
include('../config/auth.php');

require_role('technician');

include('../includes/header.php');
include('../includes/sidebar-tech.php');

$tech_id = (int)($_SESSION['uid'] ?? 0);


/* =========================================================
   GET AMC MAINTENANCE STATUS
========================================================= */

$sq = $conn->prepare("
    SELECT
        mj.id,
        mj.created_at,
        mj.status AS job_status,
        mj.technician_response,
        mj.technician_reject_reason,
        mj.owner_remark,
        a.asset_name,
        a.status AS asset_status
    FROM maintenance_jobs mj
    JOIN assets a
        ON a.id = mj.asset_id
    WHERE mj.technician_id = ?
      AND LOWER(TRIM(COALESCE(mj.job_type, '')))
          NOT IN ('fortnightly', 'saf_inspection')
    ORDER BY mj.created_at DESC
");

$sq->bind_param(
    "i",
    $tech_id
);

$sq->execute();

$q = $sq->get_result();

?>

<div class="content">

    <h3 class="mb-4">
        Maintenance Status
    </h3>

    <div class="table-responsive">

        <table class="table table-hover bg-white shadow">

            <thead class="table-light">

                <tr>
                    <th>Asset</th>
                    <th>Submitted On</th>
                    <th>Maintenance Status</th>
                    <th>Lift Status</th>
                </tr>

            </thead>

            <tbody>

<?php

if ($q->num_rows === 0) {

    echo "
        <tr>
            <td colspan='4' class='text-center text-muted py-4'>
                No maintenance submitted
            </td>
        </tr>
    ";

}


while ($r = $q->fetch_assoc()) {

    /* =====================================================
       JOB STATUS
    ===================================================== */

    $jobStatus = strtolower(
        trim((string)$r['job_status'])
    );


    switch ($jobStatus) {

        case 'assigned':

            $jobBadge = "
                <span class='badge bg-primary'>
                    Assigned
                </span>
            ";

            break;


        case 'in_progress':

            $jobBadge = "
                <span class='badge bg-warning text-dark'>
                    Under Maintenance
                </span>
            ";

            break;


        case 'pending_owner':

            $jobBadge = "
                <span class='badge bg-warning text-dark'>
                    Pending Owner Approval
                </span>
            ";

            break;


        case 'approved':

            $jobBadge = "
                <span class='badge bg-success'>
                    Owner Approved
                </span>
            ";

            break;


        case 'closed':

            $jobBadge = "
                <span class='badge bg-success'>
                    Closed
                </span>
            ";

            break;


        case 'rejected':

            $jobBadge = "
                <span class='badge bg-danger'>
                    Rejected / Rework
                </span>
            ";

            break;


        default:

            $jobBadge = "
                <span class='badge bg-secondary'>
                    " . htmlspecialchars(
                        ucfirst(str_replace('_', ' ', $jobStatus)),
                        ENT_QUOTES,
                        'UTF-8'
                    ) . "
                </span>
            ";

            break;
    }


    /* =====================================================
       ASSET STATUS
    ===================================================== */

    $assetStatus = strtolower(
        trim((string)$r['asset_status'])
    );


    if ($assetStatus === 'active') {

        $assetBadge = "
            <span class='badge bg-success'>
                Active
            </span>
        ";

    } elseif ($assetStatus === 'under maintenance') {

        $assetBadge = "
            <span class='badge bg-warning text-dark'>
                Under Maintenance
            </span>
        ";

    } elseif ($assetStatus === 'out of service') {

        $assetBadge = "
            <span class='badge bg-danger'>
                Out of Service
            </span>
        ";

    } else {

        $assetBadge = "
            <span class='badge bg-secondary'>
                " .
                htmlspecialchars(
                    $r['asset_status'] ?? 'Unknown',
                    ENT_QUOTES,
                    'UTF-8'
                )
                . "
            </span>
        ";
    }


    /* =====================================================
       ASSET NAME ESCAPE
    ===================================================== */

    $assetName = htmlspecialchars(
        $r['asset_name'] ?? '',
        ENT_QUOTES,
        'UTF-8'
    );


    /* =====================================================
       CREATED DATE
    ===================================================== */

    $createdDate = '';

    if (!empty($r['created_at'])) {

        $createdDate = format_dt(
            $r['created_at']
        );
    }


    echo "
        <tr>

            <td>
                {$assetName}
            </td>

            <td>
                {$createdDate}
            </td>

            <td>
                {$jobBadge}
            </td>

            <td>
                {$assetBadge}
            </td>

        </tr>
    ";
}

?>

            </tbody>

        </table>

    </div>

</div>

<?php include('../includes/footer.php'); ?>