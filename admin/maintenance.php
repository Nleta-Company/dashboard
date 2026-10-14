```php
<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<div class="content">

<h3 class="mb-4">Maintenance Records</h3>


<!-- =========================================================
     STATUS FILTER
========================================================= -->

<div class="card shadow-sm mb-3">

    <div class="card-body">

        <div class="row g-3 align-items-end">

            <!-- SEARCH -->

            <div class="col-md-5">

                <label class="form-label fw-semibold">
                    Search Asset
                </label>

                <input
                    type="text"
                    id="assetSearch"
                    class="form-control"
                    placeholder="Search asset name..."
                >

            </div>


            <!-- STATUS -->

            <div class="col-md-4">

                <label class="form-label fw-semibold">
                    Filter Status
                </label>

                <select
                    id="statusFilter"
                    class="form-select">

                    <option value="all">
                        All Status
                    </option>

                    <option value="ok">
                        OK
                    </option>

                    <option value="due">
                        Due
                    </option>

                    <option value="due_soon">
                        Due Soon
                    </option>

                    <option value="overdue">
                        Overdue
                    </option>

                    <option value="in_progress">
                        In Progress
                    </option>

                </select>

            </div>


            <!-- RESET -->

            <div class="col-md-3">

                <button
                    type="button"
                    id="resetFilter"
                    class="btn btn-secondary w-100">

                    Reset Filter

                </button>

            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     TABLE
========================================================= -->

<div class="table-responsive">

<table
    id="maintenanceTable"
    class="table table-hover bg-white shadow">

<thead class="table-dark">

<tr>

    <th>Asset</th>

    <th>Last Maintenance</th>

    <th>Next Due</th>

    <th>Status</th>

    <th>Remarks</th>

    <th>File</th>

</tr>

</thead>


<tbody>

<?php

/* =========================================================
   GET ALL ASSETS
========================================================= */

$q = $conn->query("
    SELECT

        a.id AS asset_id,
        a.asset_name,
        a.maintenance_interval,
        a.updated_at,

        mr.maintenance_date,
        mr.remarks,

        mj.file AS job_file

    FROM assets a

    LEFT JOIN maintenance_records mr
        ON mr.id = (

            SELECT mr2.id

            FROM maintenance_records mr2

            WHERE mr2.asset_id = a.id

              AND mr2.status = 'approved'

            ORDER BY mr2.id DESC

            LIMIT 1
        )

    LEFT JOIN maintenance_jobs mj
        ON mj.id = (

            SELECT mj2.id

            FROM maintenance_jobs mj2

            WHERE mj2.asset_id = a.id

              AND mj2.status = 'approved'

            ORDER BY mj2.id DESC

            LIMIT 1
        )

    ORDER BY a.asset_name ASC
");


if (!$q) {

    echo "
    <tr>

        <td
            colspan='6'
            class='text-center text-danger'>

            Database Error:
            " . htmlspecialchars($conn->error) . "

        </td>

    </tr>
    ";

} elseif ($q->num_rows === 0) {

    echo "
    <tr>

        <td
            colspan='6'
            class='text-center text-muted'>

            No assets found.

        </td>

    </tr>
    ";

} else {


    while ($r = $q->fetch_assoc()) {


        $assetId =
            (int)$r['asset_id'];


        $assetName =
            htmlspecialchars(
                $r['asset_name'] ?? 'Unknown Asset'
            );


        $interval =
            (int)$r['maintenance_interval'];


        /*
         * Safety fallback
         */

        if ($interval <= 0) {
            $interval = 30;
        }


        /* =====================================================
           DEFAULT VALUES
        ===================================================== */

        $lastMaintenance =
            "<span class='text-muted'>
                No Previous Record
            </span>";


        $nextDue =
            "<span class='text-muted'>
                No Due Date
            </span>";


        $remarksHtml =
            "<span class='text-muted'>
                —
            </span>";


        $fileHtml =
            "<span class='text-muted'>
                No file
            </span>";


        $statusKey = '';

        $statusText = '';

        $statusBadge = '';


        /* =====================================================
           REMARKS
        ===================================================== */

        if (!empty($r['remarks'])) {

            $remarksHtml =
                nl2br(
                    htmlspecialchars(
                        $r['remarks']
                    )
                );
        }


        /* =====================================================
           MAINTENANCE STATUS
        ===================================================== */

        /*
         * Priority 1:
         * Use the latest APPROVED maintenance record.
         *
         * Priority 2:
         * If no approved maintenance exists,
         * use assets.updated_at as the maintenance baseline.
         */

        if (!empty($r['maintenance_date'])) {


            /* =================================================
               APPROVED MAINTENANCE EXISTS
            ================================================= */

            $lastMaintenance =
                htmlspecialchars(
                    date(
                        'Y-m-d',
                        strtotime(
                            $r['maintenance_date']
                        )
                    )
                );


            $lastDate =
                strtotime(
                    $r['maintenance_date']
                );


        } elseif (!empty($r['updated_at'])) {


            /* =================================================
               NO APPROVED RECORD
               USE RESTORED ASSET DATE
            ================================================= */

            $lastMaintenance =
                htmlspecialchars(
                    date(
                        'Y-m-d',
                        strtotime(
                            $r['updated_at']
                        )
                    )
                );


            $lastDate =
                strtotime(
                    $r['updated_at']
                );


        } else {


            /* =================================================
               NO DATE AVAILABLE
            ================================================= */

            $lastDate = false;

        }


        /* =====================================================
           CALCULATE NEXT DUE + STATUS
        ===================================================== */

        if ($lastDate !== false) {


            /*
             * Calculate next maintenance date
             */

            $nextDueTimestamp =
                strtotime(
                    "+{$interval} days",
                    $lastDate
                );


            $nextDue =
                date(
                    'Y-m-d',
                    $nextDueTimestamp
                );


            /*
             * Today
             */

            $today =
                strtotime(
                    date('Y-m-d')
                );


            /*
             * Days remaining until due
             */

            $daysUntilDue =
                floor(
                    (
                        $nextDueTimestamp -
                        $today
                    ) / 86400
                );


            /* =================================================
               STATUS LOGIC
            ================================================= */


            if ($daysUntilDue < 0) {

                $statusKey =
                    'overdue';

                $statusText =
                    'Overdue';

                $statusBadge =
                    'bg-danger';


            } elseif ($daysUntilDue === 0) {

                $statusKey =
                    'due';

                $statusText =
                    'Due';

                $statusBadge =
                    'bg-warning text-dark';


            } elseif ($daysUntilDue <= 5) {

                $statusKey =
                    'due_soon';

                $statusText =
                    'Due Soon';

                $statusBadge =
                    'bg-warning text-dark';


            } else {

                $statusKey =
                    'ok';

                $statusText =
                    'OK';

                $statusBadge =
                    'bg-success';

            }


        } else {


            /*
             * No maintenance date and no asset date.
             *
             * Do NOT incorrectly mark as Due.
             */

            $statusKey =
                'ok';

            $statusText =
                'OK';

            $statusBadge =
                'bg-success';

        }


        /* =====================================================
           CHECK ACTIVE JOB
        ===================================================== */

        /*
         * Only show In Progress when there is an actual
         * maintenance job currently being worked on.
         *
         * We do NOT automatically replace Overdue/Due Soon
         * because an old assigned job exists.
         */

        $jobCheck = $conn->prepare("
            SELECT
                id,
                status

            FROM maintenance_jobs

            WHERE asset_id = ?

              AND status = 'in_progress'

            ORDER BY id DESC

            LIMIT 1
        ");


        $jobCheck->bind_param(
            "i",
            $assetId
        );


        $jobCheck->execute();


        $jobResult =
            $jobCheck->get_result();


        /*
         * Only a real in_progress job
         * changes status to In Progress.
         */

        if ($jobResult->num_rows > 0) {

            $statusKey =
                'in_progress';

            $statusText =
                'In Progress';

            $statusBadge =
                'bg-primary';

        }


        /* =====================================================
           FILE
        ===================================================== */

        if (!empty($r['job_file'])) {


            $safeFile =
                basename(
                    $r['job_file']
                );


            $filePath =
                __DIR__ .
                '/../uploads/' .
                $safeFile;


            if (file_exists($filePath)) {

                $fileHtml = "

                    <a
                        href='../uploads/" .
                        htmlspecialchars($safeFile) .
                        "'
                        target='_blank'
                        class='btn btn-outline-primary btn-sm'>

                        View

                    </a>

                ";

            } else {

                $fileHtml = "

                    <span
                        class='badge bg-warning text-dark'>

                        File Not Found

                    </span>

                ";
            }
        }


        /* =====================================================
           OUTPUT ROW
        ===================================================== */

        echo "

        <tr
            data-status='" .
            htmlspecialchars($statusKey) .
            "'>

            <td class='fw-semibold'>

                {$assetName}

            </td>


            <td>

                {$lastMaintenance}

            </td>


            <td>

                {$nextDue}

            </td>


            <td>

                <span
                    class='badge {$statusBadge}'>

                    {$statusText}

                </span>

            </td>


            <td>

                {$remarksHtml}

            </td>


            <td>

                {$fileHtml}

            </td>

        </tr>

        ";
    }
}

?>

</tbody>

</table>

</div>

</div>


<?php include('../includes/footer.php'); ?>


<script>

/* =========================================================
   SEARCH + STATUS FILTER
========================================================= */

const searchInput =
    document.getElementById(
        'assetSearch'
    );


const statusFilter =
    document.getElementById(
        'statusFilter'
    );


const resetButton =
    document.getElementById(
        'resetFilter'
    );


function filterMaintenanceTable() {


    const search =
        (searchInput.value || '')
        .toLowerCase()
        .trim();


    const selectedStatus =
        statusFilter.value;


    const rows =
        document.querySelectorAll(
            '#maintenanceTable tbody tr'
        );


    rows.forEach(function(row) {


        const assetName =
            row.cells[0]
                ? row.cells[0]
                    .innerText
                    .toLowerCase()
                : '';


        const rowStatus =
            row.getAttribute(
                'data-status'
            );


        const matchesSearch =
            assetName.includes(
                search
            );


        const matchesStatus =
            selectedStatus === 'all' ||
            rowStatus === selectedStatus;


        if (
            matchesSearch &&
            matchesStatus
        ) {

            row.style.display = '';

        } else {

            row.style.display = 'none';

        }

    });

}


/* =========================================================
   SEARCH
========================================================= */

if (searchInput) {

    searchInput.addEventListener(
        'input',
        filterMaintenanceTable
    );

}


/* =========================================================
   STATUS
========================================================= */

if (statusFilter) {

    statusFilter.addEventListener(
        'change',
        filterMaintenanceTable
    );

}


/* =========================================================
   RESET
========================================================= */

if (resetButton) {

    resetButton.addEventListener(
        'click',
        function() {

            searchInput.value = '';

            statusFilter.value =
                'all';

            filterMaintenanceTable();

        }
    );

}

</script>
```
