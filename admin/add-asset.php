<?php

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');

$owners = $conn->query("
    SELECT id, name
    FROM users
    WHERE role='owner'
    ORDER BY name ASC
");

$error = '';

/* =========================================================
   INDIAN STATES + UNION TERRITORIES
========================================================= */

$states = [
    'Andhra Pradesh',
    'Arunachal Pradesh',
    'Assam',
    'Bihar',
    'Chhattisgarh',
    'Goa',
    'Gujarat',
    'Haryana',
    'Himachal Pradesh',
    'Jharkhand',
    'Karnataka',
    'Kerala',
    'Madhya Pradesh',
    'Maharashtra',
    'Manipur',
    'Meghalaya',
    'Mizoram',
    'Nagaland',
    'Odisha',
    'Punjab',
    'Rajasthan',
    'Sikkim',
    'Tamil Nadu',
    'Telangana',
    'Tripura',
    'Uttar Pradesh',
    'Uttarakhand',
    'West Bengal',

    'Andaman and Nicobar Islands',
    'Chandigarh',
    'Dadra and Nagar Haveli and Daman and Diu',
    'Delhi',
    'Jammu and Kashmir',
    'Ladakh',
    'Lakshadweep',
    'Puducherry'
];


/* =========================================================
   STATE SHORT CODE
========================================================= */

function getStateCode($state)
{
    $codes = [

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

    return $codes[$state] ?? 'XX';
}


/* =========================================================
   MAKE SHORT SOCIETY CODE
========================================================= */

function makeSocietyShortCode($name)
{
    $name = trim($name);

    /*
     * Remove special characters but keep spaces.
     */
    $name = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $name);

    /*
     * Remove extra spaces.
     */
    $name = preg_replace('/\s+/', ' ', $name);

    $name = trim($name);

    if ($name === '') {
        return 'SOC';
    }

    /*
     * Words that should not normally participate
     * in the short code.
     */
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

    $words = preg_split('/\s+/', $name);

    $letters = [];

    foreach ($words as $word) {

        $upperWord = strtoupper($word);

        if (
            $word !== '' &&
            !in_array($upperWord, $ignoreWords, true)
        ) {
            $letters[] = strtoupper($word[0]);
        }
    }

    /*
     * If everything was removed, use first letters
     * of the original words.
     */
    if (empty($letters)) {

        foreach ($words as $word) {

            if ($word !== '') {
                $letters[] = strtoupper($word[0]);
            }
        }
    }

    /*
     * Maximum 6 characters for a clean Asset ID.
     */
    $shortCode = implode('', $letters);

    $shortCode = substr($shortCode, 0, 6);

    return $shortCode ?: 'SOC';
}


/* =========================================================
   SAVE ASSET
========================================================= */

if (isset($_POST['save'])) {

    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {

        $error = 'Invalid request. Please try again.';

    } else {

        $name     = trim($_POST['asset_name'] ?? '');
        $location = trim($_POST['location'] ?? '');
        $type     = $_POST['asset_type'] ?? '';
        $category = $_POST['category'] ?? '';
        $owner_id = (int)($_POST['owner_id'] ?? 0);
        $start    = $_POST['maintenance_start_date'] ?? '';
        $state    = trim($_POST['state'] ?? '');

        $allowed_types = [
            'Lift',
            'Escalator',
            'Travelator'
        ];

        $allowed_cats = [
            'I',
            'II',
            'III'
        ];

        $intervalMap = [
            'I'   => 30,
            'II'  => 15,
            'III' => 90
        ];


        /* =================================================
           VALIDATION
        ================================================= */

        if (
            !$name ||
            !$owner_id ||
            !$start ||
            !$state ||
            !in_array($type, $allowed_types, true) ||
            !in_array($category, $allowed_cats, true) ||
            !in_array($state, $states, true)
        ) {

            $error = "Please fill all required fields correctly.";

        } else {

            $interval = $intervalMap[$category];


            /* =================================================
               GET OWNER / SOCIETY
            ================================================= */

            $ownerStmt = $conn->prepare("
                SELECT name
                FROM users
                WHERE id = ?
                  AND role = 'owner'
                LIMIT 1
            ");

            $ownerStmt->bind_param(
                "i",
                $owner_id
            );

            $ownerStmt->execute();

            $ownerResult = $ownerStmt->get_result();

            $owner = $ownerResult->fetch_assoc();

            if (!$owner) {

                $error = "Invalid owner selected.";

            } else {

                $societyName = $owner['name'];


                /* =================================================
                   SHORT SOCIETY CODE
                ================================================= */

                $societyCode =
                    makeSocietyShortCode($societyName);


                /* =================================================
                   STATE CODE
                ================================================= */

                $stateCode =
                    getStateCode($state);


                /* =================================================
                   PREFIX
                ================================================= */

                $prefix =
                    $societyCode .
                    '-' .
                    $stateCode .
                    '-';


                $likePrefix =
                    $prefix . '%';


                /* =================================================
                   FIND NEXT UNIQUE NUMBER
                ================================================= */

                $numberStmt = $conn->prepare("
                    SELECT display_asset_id
                    FROM assets
                    WHERE display_asset_id LIKE ?
                    ORDER BY id DESC
                ");

                $numberStmt->bind_param(
                    "s",
                    $likePrefix
                );

                $numberStmt->execute();

                $numberResult =
                    $numberStmt->get_result();

                $nextNumber = 1;

                while ($numberRow = $numberResult->fetch_assoc()) {

                    $existingId =
                        $numberRow['display_asset_id'];

                    if (
                        preg_match(
                            '/(\d+)$/',
                            $existingId,
                            $matches
                        )
                    ) {

                        $candidate =
                            (int)$matches[1];

                        if ($candidate >= $nextNumber) {
                            $nextNumber =
                                $candidate + 1;
                        }
                    }
                }


                /* =================================================
                   FINAL DISPLAY ASSET ID
                ================================================= */

                $displayAssetId =
                    $prefix .
                    str_pad(
                        $nextNumber,
                        3,
                        '0',
                        STR_PAD_LEFT
                    );


                /* =================================================
                   FINAL UNIQUE CHECK
                ================================================= */

                $checkId = $conn->prepare("
                    SELECT id
                    FROM assets
                    WHERE display_asset_id = ?
                    LIMIT 1
                ");

                $checkId->bind_param(
                    "s",
                    $displayAssetId
                );

                $checkId->execute();

                if (
                    $checkId->get_result()->num_rows > 0
                ) {

                    $error =
                        "Unable to generate a unique Asset ID. Please try again.";

                } else {


                    /* =================================================
                       INSERT ASSET
                    ================================================= */

                    $stmt = $conn->prepare("
                        INSERT INTO assets
                        (
                            display_asset_id,
                            asset_name,
                            location,
                            state,
                            asset_type,
                            category,
                            owner_id,
                            maintenance_interval,
                            maintenance_start_date,
                            updated_at
                        )
                        VALUES
                        (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                        )
                    ");

                    $stmt->bind_param(
                        "ssssssiiss",
                        $displayAssetId,
                        $name,
                        $location,
                        $state,
                        $type,
                        $category,
                        $owner_id,
                        $interval,
                        $start,
                        $start
                    );


                    if ($stmt->execute()) {

                        $asset_id =
                            $conn->insert_id;


                        /* =================================================
                           INITIAL MAINTENANCE RECORD
                        ================================================= */

                        $stmt2 = $conn->prepare("
                            INSERT INTO maintenance_records
                            (
                                asset_id,
                                maintenance_date,
                                status
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                'completed'
                            )
                        ");

                        $stmt2->bind_param(
                            "is",
                            $asset_id,
                            $start
                        );

                        $stmt2->execute();


                        $_SESSION['success'] =
                            "Asset added successfully. Display Asset ID: "
                            . $displayAssetId;

                        header(
                            "Location: assets.php"
                        );

                        exit;

                    } else {

                        $error =
                            "Unable to add asset. Please try again.";
                    }
                }
            }
        }
    }
}


include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>

<div class="content">

<h3 class="mb-4">
    Add New Asset
</h3>


<?php if ($error): ?>

<div class="alert alert-danger alert-dismissible fade show">

    <?= htmlspecialchars($error) ?>

    <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
    </button>

</div>

<?php endif; ?>


<div
    class="card p-4 shadow"
    style="max-width:650px"
>

<form method="POST">

<?= csrfField() ?>


<!-- =========================================================
     ASSET NAME
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Asset Name
    </label>

    <input
        type="text"
        name="asset_name"
        class="form-control"
        required
        maxlength="150"
    >

</div>


<!-- =========================================================
     LOCATION
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Location
    </label>

    <input
        type="text"
        name="location"
        class="form-control"
        placeholder="e.g. Crossing Republik"
    >

</div>


<!-- =========================================================
     STATE
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        State / Union Territory
    </label>

    <select
        name="state"
        class="form-select"
        required
    >

        <option value="">
            Select State / Union Territory
        </option>

        <?php foreach ($states as $stateOption): ?>

            <option
                value="<?= htmlspecialchars($stateOption) ?>"
            >
                <?= htmlspecialchars($stateOption) ?>
            </option>

        <?php endforeach; ?>

    </select>

</div>


<!-- =========================================================
     ASSET TYPE
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Asset Type
    </label>

    <select
        name="asset_type"
        class="form-select"
        required
    >

        <option value="">
            Select Type
        </option>

        <option value="Lift">
            Lift
        </option>

        <option value="Escalator">
            Escalator
        </option>

        <option value="Travelator">
            Travelator
        </option>

    </select>

</div>


<!-- =========================================================
     CATEGORY
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Maintenance Category
    </label>

    <select
        name="category"
        class="form-select"
        required
    >

        <option value="">
            Select Category
        </option>

        <option value="I">
            Category I (Monthly)
        </option>

        <option value="II">
            Category II (15 Days)
        </option>

        <option value="III">
            Category III (3 Months)
        </option>

    </select>

</div>


<!-- =========================================================
     MAINTENANCE START DATE
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Maintenance Start Date
    </label>

    <input
        type="date"
        name="maintenance_start_date"
        class="form-control"
        required
    >

</div>


<!-- =========================================================
     OWNER
========================================================= -->

<div class="mb-3">

    <label class="form-label">
        Assign Owner (RWA)
    </label>

    <select
        name="owner_id"
        class="form-select"
        required
    >

        <option value="">
            Select Owner
        </option>

        <?php while ($o = $owners->fetch_assoc()): ?>

            <option
                value="<?= (int)$o['id'] ?>"
            >
                <?= htmlspecialchars($o['name']) ?>
            </option>

        <?php endwhile; ?>

    </select>

</div>


<!-- =========================================================
     DISPLAY ASSET ID INFORMATION
========================================================= -->

<div class="alert alert-info">

    <strong>Display Asset ID</strong> will be generated
    automatically.

    <br><br>

    Format:

    <strong>
        Society Short Code - State Code - Number
    </strong>

    <br><br>

    Examples:

    <br>

    Green Hotel → <strong>GH-UP-001</strong>

    <br>

    Northern Railway LKO → <strong>NRL-UP-001</strong>

    <br>

    Assotech The Nest → <strong>ATN-UP-001</strong>

</div>


<!-- =========================================================
     SAVE
========================================================= -->

<button
    name="save"
    class="btn btn-success w-100"
>

    Save Asset

</button>


<a
    href="assets.php"
    class="btn btn-secondary w-100 mt-2"
>

    ← Back

</a>

</form>

</div>

</div>

<?php include('../includes/footer.php'); ?>