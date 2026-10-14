<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('technician');

$tech_id = (int)$_SESSION['uid'];

/* =========================================================
   TIMEZONE — INDIA STANDARD TIME
========================================================= */

$IST = new DateTimeZone('Asia/Kolkata');
$UTC = new DateTimeZone('UTC');

/*
 * Keep PHP's default timezone in IST.
 */
date_default_timezone_set('Asia/Kolkata');

/*
 * Make database-generated NOW() values use IST for this
 * connection as well. This affects values such as
 * technician_responded_at.
 */
if ($conn instanceof mysqli) {
    @mysqli_query($conn, "SET time_zone = '+05:30'");
}

$todayIST = new DateTime(
    'today',
    $IST
);


/* =========================================================
   HELPER — DATABASE DATE/DATETIME TO IST
========================================================= */

/*
 * DATE fields (Y-m-d) are calendar dates and must NOT be
 * shifted by +5:30.
 *
 * The MySQL connection is explicitly set to +05:30 above,
 * therefore database-generated DATETIME/TIMESTAMP values
 * returned by this connection are already in IST.
 */
function assignedLiftsDateToIST($value)
{
    global $IST;

    if (empty($value)) {
        return null;
    }

    try {

        $value = trim((string)$value);

        /* DATE only — preserve calendar date */
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value)) {

            return new DateTime(
                $value . ' 00:00:00',
                $IST
            );
        }

        /* DATETIME/TIMESTAMP — already IST */
        $dt = new DateTime(
            $value,
            $IST
        );

        $dt->setTimezone($IST);

        return $dt;

    } catch (Exception $e) {

        return null;
    }
}


/* =========================================================
   HELPER: DISPLAY ASSET ID
   Format:
   SOCIETY SHORT NAME - STATE CODE - UNIQUE NUMBER
   Example: GHR-UP-001
========================================================= */

function getDisplayAssetId($assetName, $ownerName, $state, $assetId)
{
    $ownerName = trim($ownerName ?? '');
    $state     = trim($state ?? '');

    /*
     * Create owner/society short form.
     * Example:
     * Green Hotel -> GH
     * Northern Railway LKO -> NRL
     */
    $words = preg_split('/\s+/', $ownerName);

    $short = '';

    foreach ($words as $word) {

        $word = preg_replace('/[^A-Za-z0-9]/', '', $word);

        if ($word !== '') {
            $short .= strtoupper(substr($word, 0, 1));
        }

        if (strlen($short) >= 4) {
            break;
        }
    }

    /*
     * If owner name is unavailable,
     * use first letters from asset name.
     */
    if ($short === '') {

        $words = preg_split('/\s+/', trim($assetName));

        foreach ($words as $word) {

            $word = preg_replace('/[^A-Za-z0-9]/', '', $word);

            if ($word !== '') {
                $short .= strtoupper(substr($word, 0, 1));
            }

            if (strlen($short) >= 4) {
                break;
            }
        }
    }

    if ($short === '') {
        $short = 'AST';
    }

    /*
     * State short code.
     *
     * Existing database stores full state name.
     */
    $stateCodes = [

        'Andhra Pradesh'       => 'AP',
        'Arunachal Pradesh'    => 'AR',
        'Assam'                => 'AS',
        'Bihar'                => 'BR',
        'Chhattisgarh'         => 'CG',
        'Goa'                  => 'GA',
        'Gujarat'              => 'GJ',
        'Haryana'              => 'HR',
        'Himachal Pradesh'     => 'HP',
        'Jharkhand'            => 'JH',
        'Karnataka'            => 'KA',
        'Kerala'               => 'KL',
        'Madhya Pradesh'       => 'MP',
        'Maharashtra'          => 'MH',
        'Manipur'              => 'MN',
        'Meghalaya'             => 'ML',
        'Mizoram'               => 'MZ',
        'Nagaland'             => 'NL',
        'Odisha'               => 'OD',
        'Punjab'               => 'PB',
        'Rajasthan'            => 'RJ',
        'Sikkim'               => 'SK',
        'Tamil Nadu'           => 'TN',
        'Telangana'            => 'TG',
        'Tripura'              => 'TR',
        'Uttar Pradesh'        => 'UP',
        'Uttarakhand'          => 'UK',
        'West Bengal'          => 'WB',

        'Andaman and Nicobar Islands' => 'AN',
        'Chandigarh'                   => 'CH',
        'Dadra and Nagar Haveli and Daman and Diu' => 'DN',
        'Delhi'                        => 'DL',
        'Jammu and Kashmir'            => 'JK',
        'Ladakh'                       => 'LA',
        'Lakshadweep'                  => 'LD',
        'Puducherry'                   => 'PY'
    ];

    $stateCode = $stateCodes[$state] ?? strtoupper(substr(
        preg_replace('/[^A-Za-z]/', '', $state),
        0,
        2
    ));

    if ($stateCode === '') {
        $stateCode = 'NA';
    }

    /*
     * Existing unique asset ID is used only as
     * the unique number.
     *
     * Example:
     * Asset DB ID 83 -> 083
     */
    $uniqueNumber = str_pad(
        (string)$assetId,
        3,
        '0',
        STR_PAD_LEFT
    );

    return $short . '-' . $stateCode . '-' . $uniqueNumber;
}


/* =========================================================
   HANDLE TECHNICIAN ACCEPT / REJECT
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header("Location: assigned_lifts.php");
        exit;
    }

    $job_id = (int)(
        $_POST['job_id'] ?? 0
    );

    $response = trim(
        $_POST['technician_response'] ?? ''
    );

    $reject_reason = trim(
        $_POST['technician_reject_reason'] ?? ''
    );

    if ($job_id <= 0) {

        $_SESSION['error'] =
            'Invalid maintenance request.';

        header("Location: assigned_lifts.php");
        exit;
    }

    $allowedReasons = [

        'Already assigned to another job',
        'Not available at this time',
        'Location too far',
        'Emergency work assigned',
        'Leave / unavailable',
        'Insufficient time to attend',
        'Issue outside my responsibility',
        'Other'

    ];

    if (
        !in_array(
            $response,
            ['accepted', 'rejected'],
            true
        )
    ) {

        $_SESSION['error'] =
            'Invalid technician response.';

        header("Location: assigned_lifts.php");
        exit;
    }

    if ($response === 'rejected') {

        if ($reject_reason === '') {

            $_SESSION['error'] =
                'Please select a rejection reason.';

            header("Location: assigned_lifts.php");
            exit;
        }

        if (
            !in_array(
                $reject_reason,
                $allowedReasons,
                true
            )
        ) {

            $_SESSION['error'] =
                'Invalid rejection reason.';

            header("Location: assigned_lifts.php");
            exit;
        }
    }

    $check = $conn->prepare("
        SELECT
            id,
            asset_id,
            status,
            technician_response
        FROM maintenance_jobs
        WHERE id = ?
          AND technician_id = ?
        LIMIT 1
    ");

    if (!$check) {

        $_SESSION['error'] =
            'Unable to verify maintenance request.';

        header("Location: assigned_lifts.php");
        exit;
    }

    $check->bind_param(
        "ii",
        $job_id,
        $tech_id
    );

    $check->execute();

    $jobCheck =
        $check->get_result()->fetch_assoc();

    if (!$jobCheck) {

        $_SESSION['error'] =
            'Maintenance request not found or not assigned to you.';

        header("Location: assigned_lifts.php");
        exit;
    }

    if (
        !empty(
            $jobCheck['technician_response']
        )
    ) {

        $_SESSION['error'] =
            'You have already responded to this maintenance request.';

        header("Location: assigned_lifts.php");
        exit;
    }

    if ($response === 'accepted') {

        $upd = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                technician_response = 'accepted',
                technician_reject_reason = NULL,
                technician_responded_at = NOW()
            WHERE id = ?
              AND technician_id = ?
              AND status = 'assigned'
              AND technician_response IS NULL
        ");

        if (!$upd) {

            $_SESSION['error'] =
                'Unable to prepare acceptance request.';

            header("Location: assigned_lifts.php");
            exit;
        }

        $upd->bind_param(
            "ii",
            $job_id,
            $tech_id
        );

        if (
            $upd->execute() &&
            $upd->affected_rows > 0
        ) {

            $_SESSION['success'] =
                'Maintenance request accepted. You can now start the work.';

        } else {

            $_SESSION['error'] =
                'Unable to accept the maintenance request.';
        }

        header("Location: assigned_lifts.php");
        exit;
    }

    if ($response === 'rejected') {

        $upd = $conn->prepare("
            UPDATE maintenance_jobs
            SET
                technician_response = 'rejected',
                technician_reject_reason = ?,
                technician_responded_at = NOW(),
                status = 'rejected'
            WHERE id = ?
              AND technician_id = ?
              AND status = 'assigned'
              AND technician_response IS NULL
        ");

        if (!$upd) {

            $_SESSION['error'] =
                'Unable to prepare rejection request.';

            header("Location: assigned_lifts.php");
            exit;
        }

        $upd->bind_param(
            "sii",
            $reject_reason,
            $job_id,
            $tech_id
        );

        if (
            $upd->execute() &&
            $upd->affected_rows > 0
        ) {

            $_SESSION['success'] =
                'Maintenance request rejected successfully.';

        } else {

            $_SESSION['error'] =
                'Unable to reject the maintenance request.';
        }

        header("Location: assigned_lifts.php");
        exit;
    }
}


/* =========================================================
   HEADER / SIDEBAR
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-tech.php');

?>

<div class="content">

<h3 class="mb-4">
    Assigned Assets
</h3>

<?php if (!empty($_SESSION['success'])): ?>

<div class="alert alert-success alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['success']); ?>

    <?php unset($_SESSION['success']); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<?php if (!empty($_SESSION['error'])): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($_SESSION['error']); ?>

    <?php unset($_SESSION['error']); ?>

    <button
        type="button"
        class="btn-close"
        data-bs-dismiss="alert">
    </button>

</div>

<?php endif; ?>


<div class="table-responsive">

<table class="table table-hover bg-white shadow">

<thead class="table-dark">

<tr>

    <th>Asset ID</th>
    <th>Lift</th>
    <th>Owner</th>
    <th>Location</th>
    <th>Last Maintenance</th>
    <th>Maintenance Status</th>
    <th>Action</th>

</tr>

</thead>

<tbody>

<?php

$aq = $conn->prepare("

    SELECT

        mj.id AS job_id,
        mj.status AS job_status,
        mj.technician_response,
        mj.technician_reject_reason,
        mj.created_at AS job_created_at,

        a.id AS asset_id,
        a.asset_name,
        a.location,
        a.state,
        a.maintenance_interval,

        o.name AS owner_name,

        (
            SELECT MAX(mr.maintenance_date)
            FROM maintenance_records mr
            WHERE mr.asset_id = a.id
              AND mr.status = 'approved'
        ) AS last_maintenance

    FROM maintenance_jobs mj

    INNER JOIN (

        SELECT
            asset_id,
            MAX(id) AS latest_job_id

        FROM maintenance_jobs

        WHERE technician_id = ?

          AND LOWER(TRIM(COALESCE(job_type, ''))) NOT IN ('fortnightly', 'saf_inspection')

        GROUP BY asset_id

    ) latest

        ON latest.latest_job_id = mj.id

    INNER JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN users o
        ON o.id = a.owner_id

    WHERE mj.technician_id = ?

      AND LOWER(TRIM(COALESCE(mj.job_type, ''))) NOT IN ('fortnightly', 'saf_inspection')

    ORDER BY
        mj.created_at DESC,
        mj.id DESC

");

if (!$aq) {

    echo "

        <tr>

            <td
                colspan='7'
                class='text-center text-danger py-4'
            >

                Unable to load assigned maintenance jobs.

            </td>

        </tr>

    ";

} else {

    $aq->bind_param(
        "ii",
        $tech_id,
        $tech_id
    );

    $aq->execute();

    $q = $aq->get_result();

    if ($q->num_rows === 0) {

        echo "

        <tr>

            <td
                colspan='7'
                class='text-center text-muted py-4'
            >

                No Assets assigned to you.

            </td>

        </tr>

        ";
    }

    while ($r = $q->fetch_assoc()) {

        $lastMaint = null;
        $nextDue = null;
        $daysUntilDue = null;

        if (!empty($r['last_maintenance'])) {

            try {

                /*
                 * Convert DB maintenance value correctly:
                 * - DATE values stay on the same calendar date.
                 * - DATETIME/TIMESTAMP values are converted UTC -> IST.
                 */
                $lastMaint = assignedLiftsDateToIST(
                    $r['last_maintenance']
                );

                $interval =
                    (int)$r['maintenance_interval'];

                if (
                    $lastMaint !== null &&
                    $interval > 0
                ) {

                    $nextDue =
                        clone $lastMaint;

                    $nextDue->modify(
                        "+{$interval} days"
                    );

                    $nextDue->setTimezone($IST);

                    $daysUntilDue =
                        (int)$todayIST
                        ->diff($nextDue)
                        ->format('%r%a');
                }

            } catch (Exception $e) {

                $lastMaint = null;
                $nextDue = null;
                $daysUntilDue = null;
            }
        }

        if ($lastMaint !== null) {

            $lastMaint->setTimezone($IST);

            /*
             * maintenance_date is a DATE field, so there is no
             * meaningful time component to display here.
             * Keep the date in IST calendar context only.
             */
            $lastMaintenanceHtml = "

                <div>
                    <strong>
                        " .
                        htmlspecialchars(
                            $lastMaint->format(
                                'd M Y'
                            )
                        )
                        . "
                    </strong>
                </div>

            ";

        } else {

            $lastMaintenanceHtml = "

                <span class='text-muted'>
                    No Previous Record
                </span>

            ";
        }

        if ($daysUntilDue === null) {

            $statusHtml = "

                <span class='badge bg-secondary'>
                    No Due Date
                </span>

            ";

        } elseif ($daysUntilDue < 0) {

            $statusHtml = "

                <span class='badge bg-danger'>
                    Overdue
                </span>

            ";

        } elseif ($daysUntilDue === 0) {

            $statusHtml = "

                <span class='badge bg-danger'>
                    Due Today
                </span>

            ";

        } elseif ($daysUntilDue <= 5) {

            $statusHtml = "

                <span class='badge bg-warning text-dark'>
                    Due Soon - {$daysUntilDue} days
                </span>

            ";

        } else {

            $statusHtml = "

                <span class='badge bg-info text-dark'>
                    Due in {$daysUntilDue} days
                </span>

            ";
        }

        $jobId =
            (int)$r['job_id'];

        $assetId =
            (int)$r['asset_id'];

        $assetName =
            htmlspecialchars(
                $r['asset_name'] ?? '—'
            );

        $ownerName =
            htmlspecialchars(
                $r['owner_name'] ?? '—'
            );

        $location =
            htmlspecialchars(
                $r['location'] ?? '—'
            );

        /*
         * NEW DISPLAY ASSET ID
         */
        $displayAssetId = htmlspecialchars(
            getDisplayAssetId(
                $r['asset_name'] ?? '',
                $r['owner_name'] ?? '',
                $r['state'] ?? '',
                $assetId
            )
        );

        echo "<tr>";

        echo "

            <td>

                <strong class='text-primary'>
                    {$displayAssetId}
                </strong>

            </td>

        ";

        echo "

            <td>
                <strong>{$assetName}</strong>
            </td>

        ";

        echo "

            <td>
                {$ownerName}
            </td>

        ";

        echo "

            <td>
                {$location}
            </td>

        ";

        echo "

            <td>
                {$lastMaintenanceHtml}
            </td>

        ";

        echo "

            <td>
                {$statusHtml}
            </td>

        ";

        echo "<td>";

        if (
            empty($r['technician_response'])
            &&
            $r['job_status'] === 'assigned'
        ) {

            echo "

            <span class='badge bg-warning text-dark mb-2'>
                Waiting for Acceptance
            </span>

            <div class='mt-2'>

                <form
                    method='POST'
                    class='d-inline'
                >

                    " . csrfField() . "

                    <input
                        type='hidden'
                        name='job_id'
                        value='{$jobId}'
                    >

                    <input
                        type='hidden'
                        name='technician_response'
                        value='accepted'
                    >

                    <button
                        type='submit'
                        class='btn btn-sm btn-success'
                        onclick=\"return confirm('Accept this maintenance request?')\"
                    >
                        Accept
                    </button>

                </form>

                <button
                    type='button'
                    class='btn btn-sm btn-danger ms-1'
                    data-bs-toggle='collapse'
                    data-bs-target='#rejectBox{$jobId}'
                >
                    Reject
                </button>

            </div>

            <div
                class='collapse mt-2'
                id='rejectBox{$jobId}'
                style='max-width:320px;'
            >

                <form method='POST'>

                    " . csrfField() . "

                    <input
                        type='hidden'
                        name='job_id'
                        value='{$jobId}'
                    >

                    <input
                        type='hidden'
                        name='technician_response'
                        value='rejected'
                    >

                    <select
                        name='technician_reject_reason'
                        class='form-select form-select-sm'
                        required
                    >

                        <option value=''>
                            Select rejection reason *
                        </option>

                        <option value='Already assigned to another job'>
                            Already assigned to another job
                        </option>

                        <option value='Not available at this time'>
                            Not available at this time
                        </option>

                        <option value='Location too far'>
                            Location too far
                        </option>

                        <option value='Emergency work assigned'>
                            Emergency work assigned
                        </option>

                        <option value='Leave / unavailable'>
                            Leave / unavailable
                        </option>

                        <option value='Insufficient time to attend'>
                            Insufficient time to attend
                        </option>

                        <option value='Issue outside my responsibility'>
                            Issue outside my responsibility
                        </option>

                        <option value='Other'>
                            Other
                        </option>

                    </select>

                    <button
                        type='submit'
                        class='btn btn-danger btn-sm mt-2'
                    >
                        Confirm Rejection
                    </button>

                </form>

            </div>

            ";

        }

        elseif (
            $r['technician_response'] === 'accepted'
            &&
            $r['job_status'] === 'assigned'
        ) {

            echo "

                <span class='badge bg-success mb-2'>
                    Accepted
                </span>

            ";

            /*
             * Once technician has accepted the maintenance job,
             * Start Work must be available regardless of the
             * maintenance due-date calculation.
             *
             * Previously this button was shown only when
             * $daysUntilDue <= 5. If there was no previous
             * maintenance record, $daysUntilDue was NULL and
             * the page showed "No Action" even after acceptance.
             */
            echo "

                <br>

                <a
                    href='start_job.php?id={$jobId}'
                    class='btn btn-sm btn-warning'
                >
                    Start Work
                </a>

            ";
        }

        elseif (
            $r['technician_response'] === 'rejected'
            ||
            $r['job_status'] === 'rejected'
        ) {

            echo "

                <span class='badge bg-danger'>
                    Rejected
                </span>

            ";

            if (
                !empty(
                    $r['technician_reject_reason']
                )
            ) {

                echo "

                    <div class='small text-muted mt-1'>

                        <strong>
                            Reason:
                        </strong>

                        <br>

                        "
                        .
                        htmlspecialchars(
                            $r[
                                'technician_reject_reason'
                            ]
                        )
                        .
                        "

                    </div>

                ";
            }

        }

        elseif (
            $r['job_status'] === 'in_progress'
        ) {

            echo "

                <span class='badge bg-primary mb-2'>
                    Work In Progress
                </span>

                <br>

                <a
                    href='maintenance_submit.php?job_id={$jobId}'
                    class='btn btn-sm btn-success'
                >
                    Submit Checklist
                </a>

            ";

        }

        elseif (
            $r['job_status'] === 'pending_owner'
        ) {

            echo "

                <span class='badge bg-warning text-dark'>
                    Waiting for Owner Approval
                </span>

            ";

        }

        elseif (
            $r['job_status'] === 'approved'
        ) {

            echo "

                <a
                    href='close_job.php?id={$jobId}'
                    class='btn btn-sm btn-danger'
                    onclick=\"return confirm('Close this job?')\"
                >
                    Close Job
                </a>

            ";

        }


        elseif (
            $r['job_status'] === 'closed'
        ) {

            echo "

                <span class='badge bg-secondary'>
                    Closed
                </span>

            ";
        }

        else {


            echo "

                <span class='text-muted'>
                    No Action
                </span>

            ";
        }

        echo "</td>";

        echo "</tr>";
    }
}

?>

</tbody>

</table>

</div>

</div>

<?php include('../includes/footer.php'); ?>