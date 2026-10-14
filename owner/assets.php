<?php

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

$owner_id = (int)($_SESSION['uid'] ?? 0);

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('assets');

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   HELPER
========================================================= */

function getDisplayAssetId($assetName, $ownerName, $state, $assetId)
{
    $ownerName = trim($ownerName ?? '');
    $state     = trim($state ?? '');

    $words = preg_split('/\s+/', $ownerName);

    $short = '';

    foreach ($words as $word) {

        $word = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $word
        );

        if ($word !== '') {
            $short .= strtoupper(
                substr($word, 0, 1)
            );
        }

        if (strlen($short) >= 4) {
            break;
        }
    }

    if ($short === '') {

        $words = preg_split(
            '/\s+/',
            trim($assetName)
        );

        foreach ($words as $word) {

            $word = preg_replace(
                '/[^A-Za-z0-9]/',
                '',
                $word
            );

            if ($word !== '') {
                $short .= strtoupper(
                    substr($word, 0, 1)
                );
            }

            if (strlen($short) >= 4) {
                break;
            }
        }
    }

    if ($short === '') {
        $short = 'AST';
    }

    $stateCodes = [

        'Andhra Pradesh'       => 'AP',
        'Arunachal Pradesh'    => 'AR',
        'Assam'               => 'AS',
        'Bihar'               => 'BR',
        'Chhattisgarh'         => 'CG',
        'Goa'                  => 'GA',
        'Gujarat'              => 'GJ',
        'Haryana'             => 'HR',
        'Himachal Pradesh'     => 'HP',
        'Jharkhand'            => 'JH',
        'Karnataka'            => 'KA',
        'Kerala'               => 'KL',
        'Madhya Pradesh'       => 'MP',
        'Maharashtra'          => 'MH',
        'Manipur'              => 'MN',
        'Meghalaya'            => 'ML',
        'Mizoram'              => 'MZ',
        'Nagaland'             => 'NL',
        'Odisha'               => 'OD',
        'Punjab'               => 'PB',
        'Rajasthan'            => 'RJ',
        'Sikkim'               => 'SK',
        'Tamil Nadu'          => 'TN',
        'Telangana'            => 'TG',
        'Tripura'              => 'TR',
        'Uttar Pradesh'        => 'UP',
        'Uttarakhand'          => 'UK',
        'West Bengal'          => 'WB',
        'Andaman and Nicobar Islands' => 'AN',
        'Chandigarh'           => 'CH',
        'Dadra and Nagar Haveli and Daman and Diu' => 'DN',
        'Delhi'                => 'DL',
        'Jammu and Kashmir'    => 'JK',
        'Ladakh'               => 'LA',
        'Lakshadweep'          => 'LD',
        'Puducherry'           => 'PY'
    ];

    $stateCode = $stateCodes[$state] ?? strtoupper(
        substr(
            preg_replace(
                '/[^A-Za-z]/',
                '',
                $state
            ),
            0,
            2
        )
    );

    if ($stateCode === '') {
        $stateCode = 'NA';
    }

    return $short . '-' . $stateCode . '-' .
        str_pad(
            (string)$assetId,
            3,
            '0',
            STR_PAD_LEFT
        );
}


/* =========================================================
   OWNER TECHNICIAN
========================================================= */

$stmtTech = $conn->prepare("
    SELECT assigned_technician_id
    FROM users
    WHERE id = ?
");

$stmtTech->bind_param(
    "i",
    $owner_id
);

$stmtTech->execute();

$ownerData =
    $stmtTech->get_result()->fetch_assoc();

$tech_id =
    !empty($ownerData['assigned_technician_id'])
    ? (int)$ownerData['assigned_technician_id']
    : 0;


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

<style>
/* Keep Next Maintenance Date in a single line */
.next-maintenance-col {
    min-width: 145px !important;
    width: 145px !important;
    white-space: nowrap !important;
}

table th.next-maintenance-col,
table td.next-maintenance-col {
    white-space: nowrap !important;
}
</style>

<h3 class="mb-3">
    My Assets
</h3>


<?php if (!empty($_SESSION['success'])): ?>

<div class="alert alert-success alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['success']) ?>

    <?php unset($_SESSION['success']); ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()"
    ></button>

</div>

<?php endif; ?>


<?php if (!empty($_SESSION['error'])): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['error']) ?>

    <?php unset($_SESSION['error']); ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()"
    ></button>

</div>

<?php endif; ?>


<!-- =========================================================
     WARNING MODAL
========================================================= -->

<div
    id="maintenanceWarningModal"
    class="modal fade"
    tabindex="-1"
    aria-hidden="true"
>
    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content shadow">

            <div class="modal-header">

                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    Maintenance Reminder
                </h5>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>

            <div class="modal-body">

                <div id="maintenanceWarningBody"></div>

            </div>

            <div class="modal-footer">

                <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-dismiss="modal"
                >
                    OK
                </button>

            </div>

        </div>

    </div>
</div>


<div class="table-responsive">

<table class="table table-hover bg-white shadow mt-3">

<thead class="table-dark">

<tr>
    <th>Asset ID</th>
    <th>Lift</th>
    <th>Location</th>
    <th>Status</th>
    <th>Maintenance</th>
    <th class="next-maintenance-col">Next Date</th>
    <th>Insurance</th>
    <th>Last Work</th>
    <th>Action</th>
</tr>

</thead>

<tbody>

<?php

$stmt = $conn->prepare("
    SELECT

        a.id,
        a.asset_name,
        a.location,
        a.state,
        a.status,
        a.maintenance_interval,
        a.maintenance_start_date,
        a.updated_at,

        MAX(i.expiry_date) AS expiry_date,

        (
            SELECT MAX(mr.maintenance_date)
            FROM maintenance_records mr
            WHERE mr.asset_id = a.id
              AND LOWER(TRIM(COALESCE(mr.status, '')))
                  = 'approved'
        ) AS last_maintenance,

        (
            SELECT mj.status
            FROM maintenance_jobs mj
            WHERE mj.asset_id = a.id
              AND LOWER(TRIM(COALESCE(mj.job_type, '')))
                  NOT IN ('fortnightly', 'saf_inspection')
            ORDER BY mj.created_at DESC, mj.id DESC
            LIMIT 1
        ) AS latest_job_status

    FROM assets a

    LEFT JOIN insurance i
        ON i.asset_id = a.id

    WHERE a.owner_id = ?

    GROUP BY
        a.id,
        a.asset_name,
        a.location,
        a.state,
        a.status,
        a.maintenance_interval,
        a.maintenance_start_date,
        a.updated_at

    ORDER BY a.asset_name
");

$stmt->bind_param(
    "i",
    $owner_id
);

$stmt->execute();

$q = $stmt->get_result();

if ($q->num_rows === 0) {

    echo "
    <tr>
        <td colspan='9' class='text-center text-muted'>
            No lifts assigned to you
        </td>
    </tr>";
}


$today = new DateTime(
    'today',
    new DateTimeZone('Asia/Kolkata')
);

$maintenanceWarnings = [];


while ($a = $q->fetch_assoc()) {

    $asset_id =
        (int)$a['id'];

    $interval =
        (int)$a['maintenance_interval'];


    /* =====================================================
       DISPLAY ASSET ID
    ===================================================== */

    $displayAssetId = htmlspecialchars(
        getDisplayAssetId(
            $a['asset_name'] ?? '',
            $_SESSION['name'] ?? '',
            $a['state'] ?? '',
            $asset_id
        ),
        ENT_QUOTES,
        'UTF-8'
    );


    /* =====================================================
       LAST APPROVED MAINTENANCE
    ===================================================== */

    if (!empty($a['last_maintenance'])) {

        $lastMaintDate =
            $a['last_maintenance'];

    } elseif (!empty($a['maintenance_start_date'])) {

        $lastMaintDate =
            $a['maintenance_start_date'];

    } elseif (!empty($a['updated_at'])) {

        $lastMaintDate =
            $a['updated_at'];

    } else {

        $lastMaintDate = null;
    }


    try {

        $last = $lastMaintDate
            ? new DateTime(
                $lastMaintDate,
                new DateTimeZone('Asia/Kolkata')
            )
            : null;

    } catch (Exception $e) {

        $last = null;
    }


    /* =====================================================
       NEXT DUE
    ===================================================== */

    $due = null;

    if ($last && $interval > 0) {

        $due = clone $last;

        $due->modify(
            "+{$interval} days"
        );
    }


    $daysUntilDue = null;
    $autoReported = false;


    /* =====================================================
       AUTOMATIC TECHNICIAN REQUEST
    ===================================================== */

    if (
        $due &&
        $tech_id > 0
    ) {

        $daysUntilDue =
            (int)$today
            ->diff($due)
            ->format('%r%a');


        /*
         * Warning/request starts 5 days before due.
         * Continue after due date until maintenance is approved.
         */
        if ($daysUntilDue <= 5) {

            /* ---------------------------------------------
               ACTIVE / REWORK JOB CHECK
            --------------------------------------------- */

            $chk = $conn->prepare("
                SELECT id
                FROM maintenance_jobs
                WHERE asset_id = ?
                  AND LOWER(TRIM(COALESCE(job_type, '')))
                      NOT IN ('fortnightly', 'saf_inspection')
                  AND status IN (
                      'assigned',
                      'in_progress',
                      'pending_owner',
                      'rejected'
                  )
                LIMIT 1
            ");

            $chk->bind_param(
                "i",
                $asset_id
            );

            $chk->execute();

            $existingJob =
                $chk->get_result()->fetch_assoc();

            $chk->close();


            /* ---------------------------------------------
               CURRENT CYCLE START
            --------------------------------------------- */

            $cycleStart = clone $due;

            $cycleStart->modify('-5 days');

            $cycleStartSql =
                $cycleStart->format(
                    'Y-m-d 00:00:00'
                );


            /* ---------------------------------------------
               CYCLE JOB CHECK
            --------------------------------------------- */

            $cycleChk = $conn->prepare("
                SELECT id
                FROM maintenance_jobs
                WHERE asset_id = ?
                  AND LOWER(TRIM(COALESCE(job_type, '')))
                      NOT IN ('fortnightly', 'saf_inspection')
                  AND created_at >= ?
                LIMIT 1
            ");

            $cycleChk->bind_param(
                "is",
                $asset_id,
                $cycleStartSql
            );

            $cycleChk->execute();

            $cycleJob =
                $cycleChk
                ->get_result()
                ->fetch_assoc();

            $cycleChk->close();


            /* ---------------------------------------------
               CREATE ONLY ONE REQUEST
            --------------------------------------------- */

            if (
                !$existingJob &&
                !$cycleJob
            ) {

                $ins = $conn->prepare("
                    INSERT INTO maintenance_jobs
                    (
                        asset_id,
                        owner_id,
                        technician_id,
                        status
                    )
                    VALUES
                    (?, ?, ?, 'assigned')
                ");

                $ins->bind_param(
                    "iii",
                    $asset_id,
                    $owner_id,
                    $tech_id
                );

                if ($ins->execute()) {

                    $autoReported = true;
                }

                $ins->close();
            }
        }
    }


    /* =====================================================
       MAINTENANCE DISPLAY
    ===================================================== */

    if (!$due) {

        $mText =
            'Not Set';

        $mBadge =
            'secondary';

    } else {

        if ($daysUntilDue === null) {

            $daysUntilDue =
                (int)$today
                ->diff($due)
                ->format('%r%a');
        }


        if ($daysUntilDue < 0) {

            $mText =
                'Overdue';

            $mBadge =
                'danger';

        } elseif ($daysUntilDue <= 5) {

            $mText =
                'Due Soon';

            $mBadge =
                'warning';

        } else {

            $mText =
                'OK';

            $mBadge =
                'success';
        }


        /* ---------------------------------------------
           OWNER WARNING
        --------------------------------------------- */

        if (
            $daysUntilDue >= 0 &&
            $daysUntilDue <= 5
        ) {

            if ($daysUntilDue === 0) {

                $warningText =
                    'Maintenance is due today.';

            } elseif ($daysUntilDue === 1) {

                $warningText =
                    'Maintenance is due tomorrow.';

            } else {

                $warningText =
                    'Maintenance is due in '
                    . $daysUntilDue
                    . ' days.';
            }


            $maintenanceWarnings[] = [
                'asset' => $displayAssetId,
                'text'  => $warningText
            ];
        }
    }


    /* =====================================================
       INSURANCE
    ===================================================== */

    if (!$a['expiry_date']) {

        $iText =
            'Not Added';

        $iBadge =
            'secondary';

    } else {

        try {

            $expiry = new DateTime(
                $a['expiry_date'],
                new DateTimeZone('Asia/Kolkata')
            );

            $daysLeft =
                (int)$today
                ->diff($expiry)
                ->format('%r%a');

        } catch (Exception $e) {

            $daysLeft = 99999;
        }


        if ($daysLeft < 0) {

            $iText =
                'Expired';

            $iBadge =
                'danger';

        } elseif ($daysLeft <= 30) {

            $iText =
                'Due Soon';

            $iBadge =
                'warning';

        } else {

            $iText =
                'Valid';

            $iBadge =
                'success';
        }
    }


    /* =====================================================
       DISPLAY STATUS
    ===================================================== */

    $jobStatus =
        strtolower(
            trim(
                (string)(
                    $a['latest_job_status'] ?? ''
                )
            )
        );


    if (
        in_array(
            $jobStatus,
            [
                'assigned',
                'in_progress'
            ],
            true
        )
    ) {

        $displayStatus =
            'Under Maintenance';

        $badge =
            'warning';

    } elseif (
        $jobStatus === 'pending_owner'
    ) {

        $displayStatus =
            'Approval Pending';

        $badge =
            'info';

    } elseif (
        in_array(
            $jobStatus,
            [
                'approved',
                'closed'
            ],
            true
        )
    ) {

        $displayStatus =
            'Active';

        $badge =
            'success';

    } elseif (
        $jobStatus === 'rejected'
    ) {

        /*
         * Owner rejection means maintenance needs rework.
         * Asset remains out of normal active state.
         */
        $displayStatus =
            'Maintenance Rework';

        $badge =
            'danger';

    } else {

        $displayStatus =
            $a['status'];

        $badge = match (
            $displayStatus
        ) {

            'Active'
                => 'success',

            'Under Maintenance'
                => 'warning',

            'Out of Service'
                => 'danger',

            default
                => 'secondary'
        };
    }


    /* =====================================================
       HISTORY
    ===================================================== */

    $jStmt = $conn->prepare("
        SELECT
            COUNT(*) AS job_count,
            MAX(created_at) AS last_job_date
        FROM maintenance_jobs
        WHERE asset_id = ?
          AND LOWER(TRIM(COALESCE(job_type, '')))
              NOT IN ('fortnightly', 'saf_inspection')
    ");

    $jStmt->bind_param(
        "i",
        $asset_id
    );

    $jStmt->execute();

    $jRow =
        $jStmt
        ->get_result()
        ->fetch_assoc();

    $jobCount =
        (int)$jRow['job_count'];

    $lastJobDate =
        $jRow['last_job_date'];


    /* =====================================================
       ROW
    ===================================================== */

    echo "<tr>";

    echo "
    <td>
        <strong class='text-primary'>
            {$displayAssetId}
        </strong>
    </td>
    ";

    echo "
    <td>" .
        htmlspecialchars(
            $a['asset_name'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        )
        . "</td>
    ";

    echo "
    <td>" .
        htmlspecialchars(
            $a['location'] ?? '',
            ENT_QUOTES,
            'UTF-8'
        )
        . "</td>
    ";

    echo "
    <td>
        <span class='badge bg-{$badge}'>
            " .
            htmlspecialchars(
                $displayStatus,
                ENT_QUOTES,
                'UTF-8'
            )
            . "
        </span>
    </td>
    ";

    echo "
    <td>
        <span class='badge bg-{$mBadge}'>
            {$mText}
        </span>
    </td>
    ";

    echo "
    <td class='next-maintenance-col'>
        " .
        (
            $due
                ? "<strong>" . htmlspecialchars(
                    $due->format('d M Y'),
                    ENT_QUOTES,
                    'UTF-8'
                ) . "</strong>"
                : "<span class='text-muted'>Not Set</span>"
        )
        . "
    </td>
    ";

    echo "
    <td>
        <a href='insurance.php'>
            <span class='badge bg-{$iBadge}'>
                {$iText}
            </span>
        </a>
    </td>
    ";

    echo "<td>";

    if (
        $jobCount > 0 &&
        !empty($lastJobDate)
    ) {

        try {

            $lastJob = new DateTime(
                $lastJobDate,
                new DateTimeZone('UTC')
            );

            $lastJob->setTimezone(
                new DateTimeZone('Asia/Kolkata')
            );

            echo "
            <span class='text-success'>
                <strong>{$jobCount}</strong> jobs
            </span>
            <br>
            <small class='text-muted'>
                Last: " .
                $lastJob->format('d M Y') .
                "
            </small>
            <br>
            <small class='text-muted'>" .
                $lastJob->format('h:i A') .
                "
            </small>
            ";

        } catch (Exception $e) {

            echo "
            <span class='text-muted'>
                Work history available
            </span>";
        }

    } else {

        echo "
        <span class='text-muted'>
            No work history
        </span>";
    }

    echo "</td>";


    /* =====================================================
       ACTION
    ===================================================== */

    echo "<td>";

    if ($autoReported) {

        echo "
        <span class='badge bg-info text-dark'>
            Request Sent to Technician
        </span>
        ";

    } elseif (
        $jobStatus === 'pending_owner'
    ) {

        echo "
        <span class='badge bg-info text-dark'>
            Approval Pending
        </span>
        ";

    } elseif (
        in_array(
            $jobStatus,
            [
                'assigned',
                'in_progress',
                'rejected'
            ],
            true
        )
    ) {

        echo "
        <span class='text-muted'>
            Maintenance Processing
        </span>
        ";

    } elseif (
        $daysUntilDue !== null &&
        $daysUntilDue <= 5
    ) {

        echo "
        <span class='text-muted'>
            Maintenance Processing
        </span>
        ";

    } else {

        echo "
        <span class='text-muted'>
            —
        </span>
        ";
    }

    echo "</td>";

    echo "</tr>";
}

?>

</tbody>

</table>

</div>

</div>


<?php if (!empty($maintenanceWarnings)): ?>

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const warnings =
            <?= json_encode(
                $maintenanceWarnings,
                JSON_UNESCAPED_UNICODE |
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT
            ) ?>;

        const modalElement =
            document.getElementById(
                'maintenanceWarningModal'
            );

        const bodyElement =
            document.getElementById(
                'maintenanceWarningBody'
            );

        if (
            !modalElement ||
            !bodyElement ||
            !warnings.length
        ) {
            return;
        }

        bodyElement.innerHTML =
            '<p class="mb-2">' +
            'The following maintenance is due soon:' +
            '</p>' +

            '<ul class="mb-0">' +

            warnings.map(
                function (item) {

                    return '<li><strong>' +
                        item.asset +
                        '</strong> — ' +
                        item.text +
                        '</li>';
                }
            ).join('') +

            '</ul>';


        if (
            typeof bootstrap !== 'undefined' &&
            bootstrap.Modal
        ) {

            const modal =
                bootstrap.Modal.getOrCreateInstance(
                    modalElement
                );

            modal.show();
        }
    }
);

</script>

<?php endif; ?>


<?php include('../includes/footer.php'); ?>