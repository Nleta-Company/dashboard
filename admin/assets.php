<?php

include('../config/db.php');
include('../config/auth.php');

require_role('admin');

include('../includes/header.php');
include('../includes/sidebar-admin.php');


/* =========================================================
   STATUS FILTER
========================================================= */

$filterStatus = trim($_GET['status'] ?? '');

$allowedStatuses = [
    'Active',
    'Under Maintenance',
    'Out of Service'
];

if (!in_array($filterStatus, $allowedStatuses, true)) {
    $filterStatus = '';
}


/* =========================================================
   PAGE TITLE
========================================================= */

$pageTitle = 'Assets / Lifts';

if ($filterStatus === 'Active') {
    $pageTitle = 'Active Lifts';
}

if ($filterStatus === 'Under Maintenance') {
    $pageTitle = 'Lifts Under Maintenance';
}

if ($filterStatus === 'Out of Service') {
    $pageTitle = 'Lifts Out of Service';
}


/* =========================================================
   FETCH ASSETS
========================================================= */

if ($filterStatus !== '') {

    $stmt = $conn->prepare("
        SELECT
            a.*,
            u.name AS owner_name
        FROM assets a
        LEFT JOIN users u
            ON u.id = a.owner_id
        WHERE a.status = ?
        ORDER BY a.id DESC
    ");

    $stmt->bind_param(
        "s",
        $filterStatus
    );

    $stmt->execute();

    $assets = $stmt->get_result();

} else {

    $assets = $conn->query("
        SELECT
            a.*,
            u.name AS owner_name
        FROM assets a
        LEFT JOIN users u
            ON u.id = a.owner_id
        ORDER BY a.id DESC
    ");
}

?>

<div class="content">

<div class="d-flex justify-content-between align-items-center mb-4">

  <div>

    <h3>
        <?= htmlspecialchars($pageTitle) ?>
    </h3>

    <?php if ($filterStatus !== ''): ?>

      <small class="text-muted">

        Showing:

        <strong>
            <?= htmlspecialchars($filterStatus) ?>
        </strong>

      </small>

    <?php endif; ?>

  </div>


  <div class="d-flex gap-2">

    <?php if ($filterStatus !== ''): ?>

      <a
        href="assets.php"
        class="btn btn-secondary">

        View All

      </a>

    <?php endif; ?>


    <a
      href="add-asset.php"
      class="btn btn-success">

      + Add Asset

    </a>

  </div>

</div>


<!-- =========================================================
     FILTER SUMMARY
========================================================= -->

<div class="row g-3 mb-4">


  <!-- ALL -->

  <div class="col-md-3">

    <a
      href="assets.php"
      class="text-decoration-none">

      <div class="card shadow-sm p-3 text-center">

        <strong class="fs-4">

          <?= (int)$conn->query("
              SELECT COUNT(*)
              FROM assets
          ")->fetch_row()[0] ?>

        </strong>

        <small class="text-muted">
          All Assets
        </small>

      </div>

    </a>

  </div>


  <!-- ACTIVE -->

  <div class="col-md-3">

    <a
      href="assets.php?status=Active"
      class="text-decoration-none">

      <div class="card shadow-sm p-3 text-center">

        <strong class="fs-4 text-success">

          <?= (int)$conn->query("
              SELECT COUNT(*)
              FROM assets
              WHERE status='Active'
          ")->fetch_row()[0] ?>

        </strong>

        <small class="text-muted">
          Active
        </small>

      </div>

    </a>

  </div>


  <!-- UNDER MAINTENANCE -->

  <div class="col-md-3">

    <a
      href="assets.php?status=Under%20Maintenance"
      class="text-decoration-none">

      <div class="card shadow-sm p-3 text-center">

        <strong class="fs-4 text-warning">

          <?= (int)$conn->query("
              SELECT COUNT(*)
              FROM assets
              WHERE status='Under Maintenance'
          ")->fetch_row()[0] ?>

        </strong>

        <small class="text-muted">
          Under Maintenance
        </small>

      </div>

    </a>

  </div>


  <!-- OUT OF SERVICE -->

  <div class="col-md-3">

    <a
      href="assets.php?status=Out%20of%20Service"
      class="text-decoration-none">

      <div class="card shadow-sm p-3 text-center">

        <strong class="fs-4 text-danger">

          <?= (int)$conn->query("
              SELECT COUNT(*)
              FROM assets
              WHERE status='Out of Service'
          ")->fetch_row()[0] ?>

        </strong>

        <small class="text-muted">
          Out of Service
        </small>

      </div>

    </a>

  </div>

</div>


<!-- =========================================================
     ASSET TABLE
========================================================= -->

<div class="table-responsive">

<table class="table table-hover bg-white shadow-sm">

<thead class="table-light">

<tr>

  <th>Asset ID</th>
  <th>Asset</th>
  <th>Owner</th>
  <th>Type</th>
  <th>Category</th>
  <th>Location</th>
  <th>State</th>
  <th>Status</th>

</tr>

</thead>


<tbody>

<?php

if ($assets->num_rows == 0) {

    echo "<tr>
            <td colspan='8' class='text-center'>
              No assets found
            </td>
          </tr>";
}


/* =========================================================
   ASSET ROWS
========================================================= */

while ($r = $assets->fetch_assoc()) {


    /* =====================================================
       DISPLAY ASSET ID
    ===================================================== */

    $displayAssetId = trim(
        $r['display_asset_id'] ?? ''
    );


    /*
     * If the asset already has a permanent
     * display_asset_id, use it exactly.
     *
     * For old records where it is still NULL,
     * create a display value without changing
     * the database.
     */

    if ($displayAssetId === '') {


        /* =================================================
           OWNER SHORT CODE
        ================================================= */

        $ownerName = trim(
            $r['owner_name'] ?? ''
        );


        $ownerClean = preg_replace(
            '/[^A-Za-z0-9\s]/',
            ' ',
            $ownerName
        );


        $ownerClean = preg_replace(
            '/\s+/',
            ' ',
            trim($ownerClean)
        );


        $ownerWords = $ownerClean !== ''
            ? preg_split('/\s+/', $ownerClean)
            : [];


        $ignoreWords = [
            'RWA',
            'THE',
            'AND',
            'OF',
            'FOR',
            'RESIDENT',
            'RESIDENTS',
            'WELFARE',
            'ASSOCIATION',
            'SOCIETY'
        ];


        $letters = [];


        foreach ($ownerWords as $word) {

            if (
                $word !== '' &&
                !in_array(
                    strtoupper($word),
                    $ignoreWords,
                    true
                )
            ) {

                $letters[] = strtoupper(
                    $word[0]
                );
            }
        }


        /* =================================================
           FALLBACK OWNER CODE
        ================================================= */

        if (empty($letters)) {

            foreach ($ownerWords as $word) {

                if ($word !== '') {

                    $letters[] = strtoupper(
                        $word[0]
                    );
                }
            }
        }


        $ownerCode = substr(
            implode('', $letters),
            0,
            6
        );


        if ($ownerCode === '') {

            $ownerCode = 'SOC';
        }


        /* =================================================
           STATE CODE
        ================================================= */

        $state = trim(
            $r['state'] ?? ''
        );


        if ($state !== '') {

            $stateCodes = [

                'Andhra Pradesh' => 'AP',
                'Arunachal Pradesh' => 'AR',
                'Assam' => 'AS',
                'Bihar' => 'BR',
                'Chhattisgarh' => 'CG',
                'Goa' => 'GA',
                'Gujarat' => 'GJ',
                'Haryana' => 'HR',
                'Himachal Pradesh' => 'HP',
                'Jharkhand' => 'JH',
                'Karnataka' => 'KA',
                'Kerala' => 'KL',
                'Madhya Pradesh' => 'MP',
                'Maharashtra' => 'MH',
                'Manipur' => 'MN',
                'Meghalaya' => 'ML',
                'Mizoram' => 'MZ',
                'Nagaland' => 'NL',
                'Odisha' => 'OD',
                'Punjab' => 'PB',
                'Rajasthan' => 'RJ',
                'Sikkim' => 'SK',
                'Tamil Nadu' => 'TN',
                'Telangana' => 'TG',
                'Tripura' => 'TR',
                'Uttar Pradesh' => 'UP',
                'Uttarakhand' => 'UK',
                'West Bengal' => 'WB',

                'Andaman and Nicobar Islands' => 'AN',
                'Chandigarh' => 'CH',
                'Dadra and Nagar Haveli and Daman and Diu' => 'DH',
                'Delhi' => 'DL',
                'Jammu and Kashmir' => 'JK',
                'Ladakh' => 'LA',
                'Lakshadweep' => 'LD',
                'Puducherry' => 'PY'
            ];


            $stateCode =
                $stateCodes[$state]
                ?? strtoupper(
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

        } else {

            $stateCode = 'NA';
        }


        /* =================================================
           OLD ASSET DISPLAY ID
        ================================================= */

        $displayAssetId =
            $ownerCode .
            '-' .
            $stateCode .
            '-' .
            str_pad(
                (int)$r['id'],
                3,
                '0',
                STR_PAD_LEFT
            );
    }


    /* =====================================================
       STATUS BADGE
    ===================================================== */

    $badge = match($r['status']) {

        'Active'
            => 'success',

        'Under Maintenance'
            => 'warning',

        'Out of Service'
            => 'danger',

        default
            => 'secondary'
    };

?>

<tr>


  <!-- =====================================================
       DISPLAY ASSET ID
  ===================================================== -->

  <td>

    <a
      href="asset-profile.php?id=<?= (int)$r['id'] ?>"
      class="text-decoration-none"
      title="Open Asset Profile">

      <strong class="text-primary">

        <?= htmlspecialchars(
            $displayAssetId
        ) ?>

      </strong>

    </a>

  </td>


  <!-- =====================================================
       ASSET NAME
  ===================================================== -->

  <td>

    <strong>

      <?= htmlspecialchars(
          $r['asset_name']
      ) ?>

    </strong>


    <a
      href="edit-asset.php?id=<?= (int)$r['id'] ?>"
      class="ms-2 text-decoration-none"
      title="Edit Asset">

      ✏️

    </a>

  </td>


  <!-- =====================================================
       OWNER
  ===================================================== -->

  <td>

    <?= htmlspecialchars(
        $r['owner_name'] ?? '—'
    ) ?>

  </td>


  <!-- =====================================================
       TYPE
  ===================================================== -->

  <td>

    <?= htmlspecialchars(
        $r['asset_type'] ?? '—'
    ) ?>

  </td>


  <!-- =====================================================
       CATEGORY
  ===================================================== -->

  <td>

    <?= htmlspecialchars(
        $r['category'] ?? '—'
    ) ?>

  </td>


  <!-- =====================================================
       LOCATION
  ===================================================== -->

  <td>

    <?= htmlspecialchars(
        $r['location'] ?? '—'
    ) ?>

  </td>


  <!-- =====================================================
       STATE
  ===================================================== -->

  <td>

    <?= htmlspecialchars(
        $r['state'] ?? '—'
    ) ?>

  </td>


  <!-- =====================================================
       STATUS
  ===================================================== -->

  <td>

    <span
      class="badge bg-<?= $badge ?>">

      <?= htmlspecialchars(
          $r['status']
      ) ?>

    </span>

  </td>

</tr>

<?php
}
?>

</tbody>

</table>

</div>

</div>

<?php include('../includes/footer.php'); ?>