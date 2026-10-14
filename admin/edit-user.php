<?php

/* =========================================================
   NLETA ADMIN - EDIT USER
   COMPLETE VERSION
   ---------------------------------------------------------
   Includes:
   - User update
   - Password update
   - Owner / Technician assignment
   - Safe owner deletion
   - Asset deletion
   - Service Request deletion
   - Offer / Payment cleanup
   - SAF / PAT cleanup
   - Maintenance cleanup
   - NOC / Insurance cleanup
   - Transaction + rollback
========================================================= */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

include('../config/db.php');
include('../config/auth.php');
require_once '../config/security.php';

require_role('admin');


/* =========================================================
   USER ID
========================================================= */

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {

    $_SESSION['error'] = 'Invalid user ID.';

    header("Location: users.php");
    exit;
}


/* =========================================================
   HELPER - ESCAPE OUTPUT
========================================================= */

function h($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   HELPER - CHECK TABLE
========================================================= */

$tableExists = static function (
    mysqli $db,
    string $table
): bool {

    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.tables
        WHERE table_schema = DATABASE()
          AND table_name = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "s",
        $table
    );

    $stmt->execute();

    $row =
        $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return ((int)($row['total'] ?? 0)) > 0;
};


/* =========================================================
   HELPER - CHECK COLUMN
========================================================= */

$columnExists = static function (
    mysqli $db,
    string $table,
    string $column
): bool {

    $stmt = $db->prepare("
        SELECT COUNT(*) AS total
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND column_name = ?
    ");

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        "ss",
        $table,
        $column
    );

    $stmt->execute();

    $row =
        $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    return ((int)($row['total'] ?? 0)) > 0;
};


/* =========================================================
   HELPER - DELETE WHERE INTEGER = VALUE
========================================================= */

$deleteWhereInt = static function (
    mysqli $db,
    string $table,
    string $column,
    int $value
) use ($tableExists, $columnExists): int {

    if ($value <= 0) {
        return 0;
    }

    if (
        !$tableExists($db, $table) ||
        !$columnExists($db, $table, $column)
    ) {
        return 0;
    }

    $sql = "
        DELETE FROM `{$table}`
        WHERE `{$column}` = ?
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Unable to prepare deletion from {$table}: " .
            $db->error
        );
    }

    $stmt->bind_param(
        "i",
        $value
    );

    if (!$stmt->execute()) {

        $error = $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "Unable to delete from {$table}: {$error}"
        );
    }

    $affected =
        $stmt->affected_rows;

    $stmt->close();

    return $affected;
};


/* =========================================================
   HELPER - DELETE WHERE INTEGER IN LIST
========================================================= */

$deleteWhereIntList = static function (
    mysqli $db,
    string $table,
    string $column,
    array $ids
) use ($tableExists, $columnExists): int {

    $ids =
        array_values(
            array_unique(
                array_filter(
                    array_map(
                        'intval',
                        $ids
                    ),
                    static function ($v) {
                        return $v > 0;
                    }
                )
            )
        );

    if (empty($ids)) {
        return 0;
    }

    if (
        !$tableExists($db, $table) ||
        !$columnExists($db, $table, $column)
    ) {
        return 0;
    }

    $placeholders =
        implode(
            ',',
            array_fill(
                0,
                count($ids),
                '?'
            )
        );

    $types =
        str_repeat(
            'i',
            count($ids)
        );

    $sql = "
        DELETE FROM `{$table}`
        WHERE `{$column}`
        IN ({$placeholders})
    ";

    $stmt =
        $db->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            "Unable to prepare {$table} deletion: " .
            $db->error
        );
    }

    $stmt->bind_param(
        $types,
        ...$ids
    );

    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "Unable to delete from {$table}: {$error}"
        );
    }

    $affected =
        $stmt->affected_rows;

    $stmt->close();

    return $affected;
};


/* =========================================================
   HELPER - GET INTEGER IDS
========================================================= */

$getIds = static function (
    mysqli $db,
    string $sql,
    string $types = '',
    ...$params
): array {

    $stmt =
        $db->prepare($sql);

    if (!$stmt) {

        throw new RuntimeException(
            "Unable to prepare ID query: " .
            $db->error
        );
    }

    if ($types !== '') {

        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    if (!$stmt->execute()) {

        $error =
            $stmt->error;

        $stmt->close();

        throw new RuntimeException(
            "Unable to execute ID query: {$error}"
        );
    }

    $result =
        $stmt->get_result();

    $ids = [];

    while (
        $row =
        $result->fetch_row()
    ) {

        $value =
            (int)$row[0];

        if ($value > 0) {
            $ids[] =
                $value;
        }
    }

    $stmt->close();

    return array_values(
        array_unique($ids)
    );
};


/* =========================================================
   FETCH USER
========================================================= */

$stmt =
    $conn->prepare("
        SELECT *
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

if (!$stmt) {

    die(
        "Unable to load user: " .
        h($conn->error)
    );
}

$stmt->bind_param(
    "i",
    $id
);

$stmt->execute();

$user =
    $stmt
    ->get_result()
    ->fetch_assoc();

$stmt->close();


/* =========================================================
   USER NOT FOUND
========================================================= */

if (!$user) {

    $_SESSION['error'] =
        "User not found.";

    header("Location: users.php");
    exit;
}


/* =========================================================
   FETCH TECHNICIANS
========================================================= */

$techs =
    $conn->query("
        SELECT
            id,
            name
        FROM users
        WHERE role = 'technician'
        ORDER BY name
    ");

if (!$techs) {

    die(
        "Unable to load technicians: " .
        h($conn->error)
    );
}


/* =========================================================
   UPDATE USER
========================================================= */

if (isset($_POST['update'])) {

    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       BASIC VALUES
    ===================================================== */

    $name =
        trim(
            $_POST['name'] ?? ''
        );

    $email =
        filter_var(
            trim(
                $_POST['email'] ?? ''
            ),
            FILTER_VALIDATE_EMAIL
        );

    $role =
        $_POST['role'] ?? '';


    $allowedRoles = [
        'admin',
        'owner',
        'inspector',
        'technician'
    ];


    /* =====================================================
       VALIDATION
    ===================================================== */

    if ($name === '') {

        $_SESSION['error'] =
            'Name is required.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    if (!$email) {

        $_SESSION['error'] =
            'Invalid email address.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    if (
        !in_array(
            $role,
            $allowedRoles,
            true
        )
    ) {

        $_SESSION['error'] =
            'Invalid role.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       ASSIGNED TECHNICIAN
    ===================================================== */

    $assignedTech = null;

    if (
        $role === 'owner' &&
        !empty(
            $_POST['assigned_technician_id']
        )
    ) {

        $assignedTech =
            (int)
            $_POST['assigned_technician_id'];
    }


    /* =====================================================
       PHONE
    ===================================================== */

    $phone =
        preg_replace(
            '/[^0-9]/',
            '',
            trim(
                $_POST['phone'] ?? ''
            )
        );

    $phone2 =
        preg_replace(
            '/[^0-9]/',
            '',
            trim(
                $_POST['phone2'] ?? ''
            )
        );


    $email2 =
        trim(
            $_POST['email2'] ?? ''
        );


    if (
        $phone !== '' &&
        strlen($phone) !== 10
    ) {

        $_SESSION['error'] =
            'Phone number must be exactly 10 digits.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    if (
        $phone2 !== '' &&
        strlen($phone2) !== 10
    ) {

        $_SESSION['error'] =
            'Second phone number must be exactly 10 digits.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    if (
        $email2 !== '' &&
        !filter_var(
            $email2,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        $_SESSION['error'] =
            'Invalid second email address.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       PASSWORD UPDATE
    ===================================================== */

    if (
        !empty(
            $_POST['password']
        )
    ) {

        $passwordCheck =
            validatePasswordStrength(
                $_POST['password']
            );


        if (
            !$passwordCheck['valid']
        ) {

            $_SESSION['error'] =
                $passwordCheck['error'];

            header(
                "Location: edit-user.php?id={$id}"
            );

            exit;
        }


        $password =
            password_hash(
                $_POST['password'],
                PASSWORD_BCRYPT,
                [
                    'cost' => 12
                ]
            );


        $stmt =
            $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    email = ?,
                    email2 = ?,
                    phone = ?,
                    phone2 = ?,
                    role = ?,
                    password = ?,
                    assigned_technician_id = ?
                WHERE id = ?
            ");

        if (!$stmt) {

            $_SESSION['error'] =
                "Unable to prepare user update.";

            header(
                "Location: edit-user.php?id={$id}"
            );

            exit;
        }


        $stmt->bind_param(
            "sssssssii",
            $name,
            $email,
            $email2,
            $phone,
            $phone2,
            $role,
            $password,
            $assignedTech,
            $id
        );


    } else {

        /* =================================================
           UPDATE WITHOUT PASSWORD
        ================================================= */

        $stmt =
            $conn->prepare("
                UPDATE users
                SET
                    name = ?,
                    email = ?,
                    email2 = ?,
                    phone = ?,
                    phone2 = ?,
                    role = ?,
                    assigned_technician_id = ?
                WHERE id = ?
            ");

        if (!$stmt) {

            $_SESSION['error'] =
                "Unable to prepare user update.";

            header(
                "Location: edit-user.php?id={$id}"
            );

            exit;
        }


        $stmt->bind_param(
            "ssssssii",
            $name,
            $email,
            $email2,
            $phone,
            $phone2,
            $role,
            $assignedTech,
            $id
        );
    }


    if (
        $stmt->execute()
    ) {

        $_SESSION['success'] =
            "User updated successfully.";

    } else {

        $_SESSION['error'] =
            "Unable to update user: " .
            $stmt->error;
    }


    $stmt->close();


    header(
        "Location: users.php"
    );

    exit;
}


/* =========================================================
   DELETE USER
========================================================= */

if (isset($_POST['delete_user'])) {

    /* =====================================================
       CSRF
    ===================================================== */

    if (
        !validateCSRFToken(
            $_POST['csrf_token'] ?? ''
        )
    ) {

        $_SESSION['error'] =
            'Invalid request. Please try again.';

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       ADMIN CANNOT DELETE SELF
    ===================================================== */

    if (
        (int)$user['id'] ===
        (int)$_SESSION['uid']
    ) {

        $_SESSION['error'] =
            "You cannot delete your own account.";

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       ADMIN ACCOUNT PROTECTION
    ===================================================== */

    if (
        $user['role'] === 'admin'
    ) {

        $_SESSION['error'] =
            "Admin user cannot be deleted.";

        header(
            "Location: edit-user.php?id={$id}"
        );

        exit;
    }


    /* =====================================================
       OWNER DELETE
    ===================================================== */

    if (
        $user['role'] === 'owner'
    ) {

        $conn->begin_transaction();


        try {

            /* =================================================
               STEP 1
               GET OWNER ASSET IDS
            ================================================= */

            $assetIds =
                $getIds(
                    $conn,
                    "
                    SELECT id
                    FROM assets
                    WHERE owner_id = ?
                    ",
                    "i",
                    $id
                );


            /* =================================================
               STEP 2
               GET OWNER SERVICE REQUEST IDS
            ================================================= */

            $requestIds =
                [];

            if (
                $tableExists(
                    $conn,
                    'service_requests'
                )
                &&
                $columnExists(
                    $conn,
                    'service_requests',
                    'owner_id'
                )
            ) {

                $requestIds =
                    $getIds(
                        $conn,
                        "
                        SELECT id
                        FROM service_requests
                        WHERE owner_id = ?
                        ",
                        "i",
                        $id
                    );
            }


            /* =================================================
               STEP 3
               GET OFFER IDS
            ================================================= */

            $offerIds =
                [];

            if (
                !empty($requestIds) &&
                $tableExists(
                    $conn,
                    'nleta_offer_master'
                ) &&
                $columnExists(
                    $conn,
                    'nleta_offer_master',
                    'request_id'
                )
            ) {

                $placeholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($requestIds),
                            '?'
                        )
                    );

                $types =
                    str_repeat(
                        'i',
                        count($requestIds)
                    );

                $stmt =
                    $conn->prepare("
                        SELECT id
                        FROM nleta_offer_master
                        WHERE request_id
                        IN ({$placeholders})
                    ");

                if (!$stmt) {

                    throw new RuntimeException(
                        "Unable to find owner offers: " .
                        $conn->error
                    );
                }

                $stmt->bind_param(
                    $types,
                    ...$requestIds
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                while (
                    $row =
                    $result->fetch_assoc()
                ) {

                    $offerId =
                        (int)$row['id'];

                    if (
                        $offerId > 0
                    ) {

                        $offerIds[] =
                            $offerId;
                    }
                }

                $stmt->close();
            }


            $offerIds =
                array_values(
                    array_unique(
                        $offerIds
                    )
                );


            /* =================================================
               STEP 4
               GET PAYMENT IDS
            ================================================= */

            $paymentIds =
                [];

            if (
                !empty($offerIds) &&
                $tableExists(
                    $conn,
                    'payments'
                )
            ) {

                $paymentColumn = null;

                if (
                    $columnExists(
                        $conn,
                        'payments',
                        'Offer_ID'
                    )
                ) {

                    $paymentColumn =
                        'Offer_ID';

                } elseif (
                    $columnExists(
                        $conn,
                        'payments',
                        'offer_id'
                    )
                ) {

                    $paymentColumn =
                        'offer_id';
                }


                if ($paymentColumn) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($offerIds),
                                '?'
                            )
                        );

                    $types =
                        str_repeat(
                            'i',
                            count($offerIds)
                        );

                    $stmt =
                        $conn->prepare("
                            SELECT Payment_ID
                            FROM payments
                            WHERE `{$paymentColumn}`
                            IN ({$placeholders})
                        ");

                    if ($stmt) {

                        $stmt->bind_param(
                            $types,
                            ...$offerIds
                        );

                        $stmt->execute();

                        $result =
                            $stmt->get_result();

                        while (
                            $row =
                            $result->fetch_assoc()
                        ) {

                            $paymentId =
                                (int)
                                ($row['Payment_ID'] ?? 0);

                            if (
                                $paymentId > 0
                            ) {

                                $paymentIds[] =
                                    $paymentId;
                            }
                        }

                        $stmt->close();
                    }
                }
            }


            $paymentIds =
                array_values(
                    array_unique(
                        $paymentIds
                    )
                );


            /* =================================================
               STEP 5
               GET PAYMENT SCHEDULE IDS
            ================================================= */

            $scheduleIds =
                [];

            if (
                !empty($paymentIds) &&
                $tableExists(
                    $conn,
                    'payment_schedule'
                )
            ) {

                $paymentColumn = null;

                if (
                    $columnExists(
                        $conn,
                        'payment_schedule',
                        'Payment_ID'
                    )
                ) {

                    $paymentColumn =
                        'Payment_ID';

                } elseif (
                    $columnExists(
                        $conn,
                        'payment_schedule',
                        'payment_id'
                    )
                ) {

                    $paymentColumn =
                        'payment_id';
                }


                if ($paymentColumn) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($paymentIds),
                                '?'
                            )
                        );

                    $types =
                        str_repeat(
                            'i',
                            count($paymentIds)
                        );

                    $stmt =
                        $conn->prepare("
                            SELECT Schedule_ID
                            FROM payment_schedule
                            WHERE `{$paymentColumn}`
                            IN ({$placeholders})
                        ");

                    if ($stmt) {

                        $stmt->bind_param(
                            $types,
                            ...$paymentIds
                        );

                        $stmt->execute();

                        $result =
                            $stmt->get_result();

                        while (
                            $row =
                            $result->fetch_assoc()
                        ) {

                            $scheduleId =
                                (int)
                                ($row['Schedule_ID'] ?? 0);

                            if (
                                $scheduleId > 0
                            ) {

                                $scheduleIds[] =
                                    $scheduleId;
                            }
                        }

                        $stmt->close();
                    }
                }
            }


            $scheduleIds =
                array_values(
                    array_unique(
                        $scheduleIds
                    )
                );


            /* =================================================
               STEP 6
               DELETE PAYMENT TRANSACTIONS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'payment_transactions',
                'Schedule_ID',
                $scheduleIds
            );

            $deleteWhereIntList(
                $conn,
                'payment_transactions',
                'Payment_ID',
                $paymentIds
            );

            $deleteWhereIntList(
                $conn,
                'payment_transactions',
                'Offer_ID',
                $offerIds
            );


            /* =================================================
               STEP 7
               DELETE PAYMENT SCHEDULE
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'payment_schedule',
                'Schedule_ID',
                $scheduleIds
            );

            $deleteWhereIntList(
                $conn,
                'payment_schedule',
                'Payment_ID',
                $paymentIds
            );


            /* =================================================
               STEP 8
               DELETE PAYMENTS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'payments',
                'Payment_ID',
                $paymentIds
            );

            $deleteWhereIntList(
                $conn,
                'payments',
                'Offer_ID',
                $offerIds
            );


            /* =================================================
               STEP 9
               DELETE OFFER CHILDREN
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'nleta_offer_assets',
                'offer_id',
                $offerIds
            );

            $deleteWhereIntList(
                $conn,
                'nleta_offer_items',
                'offer_id',
                $offerIds
            );


            /* =================================================
               REMOVE OWNER ASSET LINKS FROM OFFERS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'nleta_offer_assets',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 10
               DELETE OFFERS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'nleta_offer_master',
                'id',
                $offerIds
            );


            /* =================================================
               STEP 11
               DELETE SERVICE REQUEST ASSET LINKS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'service_request_assets',
                'request_id',
                $requestIds
            );


            /* =================================================
               ALSO REMOVE LINKS FOR OWNER ASSETS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'service_request_assets',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 12
               DELETE SERVICE REQUESTS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'service_requests',
                'id',
                $requestIds
            );


            /* =================================================
               STEP 13
               GET SAF JOB IDS
            ================================================= */

            $jobIds =
                [];

            if (
                $tableExists(
                    $conn,
                    'maintenance_jobs'
                )
            ) {

                $conditions = [];

                $params = [];

                $types = '';

                if (
                    $columnExists(
                        $conn,
                        'maintenance_jobs',
                        'owner_id'
                    )
                ) {

                    $conditions[] =
                        'owner_id = ?';

                    $params[] =
                        $id;

                    $types .= 'i';
                }


                if (
                    !empty($assetIds) &&
                    $columnExists(
                        $conn,
                        'maintenance_jobs',
                        'asset_id'
                    )
                ) {

                    $placeholders =
                        implode(
                            ',',
                            array_fill(
                                0,
                                count($assetIds),
                                '?'
                            )
                        );

                    $conditions[] =
                        "asset_id IN ({$placeholders})";

                    foreach (
                        $assetIds
                        as $assetId
                    ) {

                        $params[] =
                            $assetId;

                        $types .= 'i';
                    }
                }


                if (!empty($conditions)) {

                    $sql = "
                        SELECT id
                        FROM maintenance_jobs
                        WHERE " .
                        implode(
                            ' OR ',
                            $conditions
                        );

                    $stmt =
                        $conn->prepare($sql);

                    if (!$stmt) {

                        throw new RuntimeException(
                            "Unable to find maintenance jobs: " .
                            $conn->error
                        );
                    }

                    $stmt->bind_param(
                        $types,
                        ...$params
                    );

                    $stmt->execute();

                    $result =
                        $stmt->get_result();

                    while (
                        $row =
                        $result->fetch_assoc()
                    ) {

                        $jobId =
                            (int)$row['id'];

                        if (
                            $jobId > 0
                        ) {

                            $jobIds[] =
                                $jobId;
                        }
                    }

                    $stmt->close();
                }
            }


            $jobIds =
                array_values(
                    array_unique(
                        $jobIds
                    )
                );


            /* =================================================
               STEP 14
               GET SAF CONTRACT IDS
            ================================================= */

            $safIds =
                [];

            if (
                !empty($jobIds) &&
                $tableExists(
                    $conn,
                    'maintenance_jobs'
                ) &&
                $columnExists(
                    $conn,
                    'maintenance_jobs',
                    'saf_id'
                )
            ) {

                $placeholders =
                    implode(
                        ',',
                        array_fill(
                            0,
                            count($jobIds),
                            '?'
                        )
                    );

                $types =
                    str_repeat(
                        'i',
                        count($jobIds)
                    );

                $stmt =
                    $conn->prepare("
                        SELECT DISTINCT saf_id
                        FROM maintenance_jobs
                        WHERE id IN ({$placeholders})
                          AND saf_id IS NOT NULL
                          AND saf_id > 0
                    ");

                if (!$stmt) {

                    throw new RuntimeException(
                        "Unable to find SAF records: " .
                        $conn->error
                    );
                }

                $stmt->bind_param(
                    $types,
                    ...$jobIds
                );

                $stmt->execute();

                $result =
                    $stmt->get_result();

                while (
                    $row =
                    $result->fetch_assoc()
                ) {

                    $safId =
                        (int)$row['saf_id'];

                    if (
                        $safId > 0
                    ) {

                        $safIds[] =
                            $safId;
                    }
                }

                $stmt->close();
            }


            $safIds =
                array_values(
                    array_unique(
                        $safIds
                    )
                );


            /* =================================================
               STEP 15
               DELETE SAF/PAT CHECKLIST EVIDENCE
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'saf_checklist_evidence',
                'job_id',
                $jobIds
            );


            $deleteWhereIntList(
                $conn,
                'saf_checklist_evidence',
                'saf_id',
                $safIds
            );


            /* =================================================
               STEP 16
               DELETE OTHER JOB-BASED RECORDS
            ================================================= */

            $jobChildTables = [

                ['inspection_evidence', 'job_id'],
                ['inspection_results', 'job_id'],
                ['inspection_checklist', 'job_id'],
                ['checklist_results', 'job_id'],
                ['maintenance_evidence', 'job_id']

            ];


            foreach (
                $jobChildTables
                as $child
            ) {

                $deleteWhereIntList(
                    $conn,
                    $child[0],
                    $child[1],
                    $jobIds
                );
            }


            /* =================================================
               STEP 17
               DELETE MAINTENANCE RECORDS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'maintenance_records',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 18
               DELETE CALLBACKS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'callbacks',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 19
               DELETE INSURANCE
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'insurance',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 20
               DELETE NOC
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'noc_registration',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 21
               DELETE FORTNIGHTLY CHECKS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'fortnightly_checks',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 22
               DELETE ASSET DETAILS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'asset_details',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 23
               DELETE EVIDENCE
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'evidence',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 24
               DELETE NC REGISTER
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'nc_register',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 25
               DELETE CONTRACTS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'contracts',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 26
               DELETE ASSET MASTER
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'asset_master',
                'asset_id',
                $assetIds
            );


            /* =================================================
               STEP 27
               DELETE MAINTENANCE JOBS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'maintenance_jobs',
                'id',
                $jobIds
            );


            /* =================================================
               STEP 28
               DELETE SAF CONTRACTS
            ================================================= */

            $deleteWhereIntList(
                $conn,
                'saf_contracts',
                'id',
                $safIds
            );


            /* =================================================
               STEP 29
               DELETE OWNER ASSETS
            ================================================= */

            $deletedAssets = 0;

            if (
                !empty($assetIds)
            ) {

                $deletedAssets =
                    $deleteWhereIntList(
                        $conn,
                        'assets',
                        'id',
                        $assetIds
                    );

            } else {

                /*
                 * Fallback for an owner that has no
                 * asset IDs collected.
                 */

                if (
                    $tableExists(
                        $conn,
                        'assets'
                    )
                ) {

                    $stmt =
                        $conn->prepare("
                            DELETE FROM assets
                            WHERE owner_id = ?
                        ");

                    if (!$stmt) {

                        throw new RuntimeException(
                            "Unable to delete owner assets: " .
                            $conn->error
                        );
                    }

                    $stmt->bind_param(
                        "i",
                        $id
                    );

                    if (!$stmt->execute()) {

                        $error =
                            $stmt->error;

                        $stmt->close();

                        throw new RuntimeException(
                            "Unable to delete owner assets: {$error}"
                        );
                    }

                    $deletedAssets =
                        $stmt->affected_rows;

                    $stmt->close();
                }
            }


            /* =================================================
               STEP 30
               REMOVE TECHNICIAN ASSIGNMENTS
            ================================================= */

            $deleteWhereInt(
                $conn,
                'owner_technician_assignments',
                'owner_id',
                $id
            );


            /* =================================================
               STEP 31
               REMOVE LEGACY TECHNICIAN REFERENCES
            ================================================= */

            /*
             * If technicians/users contain a reference to
             * this owner, clear it only when such a column
             * exists.
             */

            if (
                $columnExists(
                    $conn,
                    'users',
                    'owner_id'
                )
            ) {

                $stmt =
                    $conn->prepare("
                        UPDATE users
                        SET owner_id = NULL
                        WHERE owner_id = ?
                    ");

                if ($stmt) {

                    $stmt->bind_param(
                        "i",
                        $id
                    );

                    $stmt->execute();

                    $stmt->close();
                }
            }


            /* =================================================
               STEP 32
               VERIFY OWNER SERVICE REQUESTS ARE GONE
            ================================================= */

            if (
                $tableExists(
                    $conn,
                    'service_requests'
                ) &&
                $columnExists(
                    $conn,
                    'service_requests',
                    'owner_id'
                )
            ) {

                $stmt =
                    $conn->prepare("
                        SELECT COUNT(*)
                        FROM service_requests
                        WHERE owner_id = ?
                    ");

                if ($stmt) {

                    $stmt->bind_param(
                        "i",
                        $id
                    );

                    $stmt->execute();

                    $row =
                        $stmt
                        ->get_result()
                        ->fetch_row();

                    $stmt->close();

                    if (
                        (int)$row[0] > 0
                    ) {

                        throw new RuntimeException(
                            "Service Requests still reference this Owner."
                        );
                    }
                }
            }


            /* =================================================
               STEP 33
               VERIFY OWNER ASSETS ARE GONE
            ================================================= */

            if (
                $tableExists(
                    $conn,
                    'assets'
                )
            ) {

                $stmt =
                    $conn->prepare("
                        SELECT COUNT(*)
                        FROM assets
                        WHERE owner_id = ?
                    ");

                if ($stmt) {

                    $stmt->bind_param(
                        "i",
                        $id
                    );

                    $stmt->execute();

                    $row =
                        $stmt
                        ->get_result()
                        ->fetch_row();

                    $stmt->close();

                    if (
                        (int)$row[0] > 0
                    ) {

                        throw new RuntimeException(
                            "Some assets still belong to this Owner."
                        );
                    }
                }
            }


            /* =================================================
               STEP 34
               DELETE OWNER ACCOUNT
            ================================================= */

            $stmt =
                $conn->prepare("
                    DELETE FROM users
                    WHERE id = ?
                      AND role = 'owner'
                    LIMIT 1
                ");

            if (!$stmt) {

                throw new RuntimeException(
                    "Unable to prepare Owner deletion: " .
                    $conn->error
                );
            }

            $stmt->bind_param(
                "i",
                $id
            );

            if (!$stmt->execute()) {

                $error =
                    $stmt->error;

                $stmt->close();

                throw new RuntimeException(
                    "Unable to delete Owner: {$error}"
                );
            }


            $deletedUser =
                $stmt->affected_rows;

            $stmt->close();


            if (
                $deletedUser !== 1
            ) {

                throw new RuntimeException(
                    "Owner account was not deleted."
                );
            }


            /* =================================================
               COMMIT
            ================================================= */

            $conn->commit();


            $_SESSION['success'] =
                "Owner account and {$deletedAssets} assigned lift(s), including their applicable Service Request, Offer, Payment and SAF/PAT records, were deleted successfully.";


        } catch (Throwable $e) {

            /* ===============================================
               ROLLBACK EVERYTHING
            ============================================== */

            $conn->rollback();


            $_SESSION['error'] =
                "Unable to delete owner and assigned lifts: " .
                $e->getMessage();
        }


    } else {

        /* =====================================================
           DELETE TECHNICIAN / OTHER NON-OWNER USER
        ===================================================== */

        $conn->begin_transaction();


        try {

            /* =================================================
               REMOVE OWNER-TECHNICIAN ASSIGNMENTS
               IF THIS USER IS A TECHNICIAN
            ================================================= */

            $deleteWhereInt(
                $conn,
                'owner_technician_assignments',
                'technician_id',
                $id
            );


            /* =================================================
               CLEAR OWNER LEGACY ASSIGNMENT
            ================================================= */

            if (
                $columnExists(
                    $conn,
                    'users',
                    'assigned_technician_id'
                )
            ) {

                $stmt =
                    $conn->prepare("
                        UPDATE users
                        SET assigned_technician_id = NULL
                        WHERE assigned_technician_id = ?
                    ");

                if ($stmt) {

                    $stmt->bind_param(
                        "i",
                        $id
                    );

                    $stmt->execute();

                    $stmt->close();
                }
            }


            /* =================================================
               DELETE USER
            ================================================= */

            $stmt =
                $conn->prepare("
                    DELETE FROM users
                    WHERE id = ?
                    LIMIT 1
                ");

            if (!$stmt) {

                throw new RuntimeException(
                    "Unable to prepare user deletion: " .
                    $conn->error
                );
            }

            $stmt->bind_param(
                "i",
                $id
            );

            if (!$stmt->execute()) {

                $error =
                    $stmt->error;

                $stmt->close();

                throw new RuntimeException(
                    "Unable to delete user: {$error}"
                );
            }


            if (
                $stmt->affected_rows !== 1
            ) {

                $stmt->close();

                throw new RuntimeException(
                    "User was not deleted."
                );
            }


            $stmt->close();


            $conn->commit();


            $_SESSION['success'] =
                "User deleted successfully.";


        } catch (Throwable $e) {

            $conn->rollback();


            $_SESSION['error'] =
                "Unable to delete user: " .
                $e->getMessage();
        }
    }


    header(
        "Location: users.php"
    );

    exit;
}


/* =========================================================
   PAGE
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-admin.php');

?>


<div class="content">

    <h3 class="mb-4">
        Edit User
    </h3>


    <!-- ===================================================
         ERROR
    ==================================================== -->

    <?php if (!empty($_SESSION['error'])): ?>

        <div
            class="alert alert-danger alert-dismissible fade show"
        >

            <?= h($_SESSION['error']) ?>

            <?php
            unset($_SESSION['error']);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ===================================================
         SUCCESS
    ==================================================== -->

    <?php if (!empty($_SESSION['success'])): ?>

        <div
            class="alert alert-success alert-dismissible fade show"
        >

            <?= h($_SESSION['success']) ?>

            <?php
            unset($_SESSION['success']);
            ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <div
        class="card p-4 shadow"
        style="max-width:520px"
    >


        <!-- =================================================
             UPDATE FORM
        ================================================== -->

        <form method="POST">

            <?= csrfField() ?>


            <!-- NAME -->

            <label class="form-label">
                Name
            </label>

            <input
                type="text"
                name="name"
                class="form-control mb-3"
                value="<?= h($user['name'] ?? '') ?>"
                required
                maxlength="100"
            >


            <!-- EMAIL -->

            <label class="form-label">
                Email 1
            </label>

            <input
                type="email"
                name="email"
                class="form-control mb-3"
                value="<?= h($user['email'] ?? '') ?>"
                required
                maxlength="100"
            >


            <!-- EMAIL 2 -->

            <label class="form-label">
                Email 2 (optional)
            </label>

            <input
                type="email"
                name="email2"
                class="form-control mb-3"
                value="<?= h($user['email2'] ?? '') ?>"
                maxlength="100"
            >


            <!-- ROLE -->

            <label class="form-label">
                Role
            </label>

            <select
                name="role"
                class="form-select mb-3"
                required
            >

                <option
                    value="technician"
                    <?= (
                        ($user['role'] ?? '') ===
                        'technician'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Technician
                </option>


                <option
                    value="inspector"
                    <?= (
                        ($user['role'] ?? '') ===
                        'inspector'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Inspector
                </option>


                <option
                    value="owner"
                    <?= (
                        ($user['role'] ?? '') ===
                        'owner'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Owner (RWA)
                </option>


                <option
                    value="admin"
                    <?= (
                        ($user['role'] ?? '') ===
                        'admin'
                    )
                        ? 'selected'
                        : ''
                    ?>
                >
                    Admin
                </option>

            </select>


            <!-- ASSIGNED TECHNICIAN -->

            <?php
            if (
                ($user['role'] ?? '') ===
                'owner'
            ):
            ?>

                <label class="form-label">
                    Assigned Technician
                </label>

                <select
                    name="assigned_technician_id"
                    class="form-select mb-3"
                >

                    <option value="">
                        -- Select Technician --
                    </option>


                    <?php while (
                        $t =
                        $techs->fetch_assoc()
                    ): ?>

                        <option
                            value="<?= (int)$t['id'] ?>"
                            <?= (
                                (int)(
                                    $user[
                                        'assigned_technician_id'
                                    ] ?? 0
                                )
                                ===
                                (int)$t['id']
                            )
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?= h(
                                $t['name']
                            ) ?>

                        </option>

                    <?php endwhile; ?>

                </select>

            <?php endif; ?>


            <!-- PHONE -->

            <label class="form-label">
                Phone 1
            </label>

            <input
                type="text"
                name="phone"
                class="form-control mb-3"
                value="<?= h($user['phone'] ?? '') ?>"
                placeholder="Primary phone number"
                maxlength="10"
                pattern="[0-9]{10}"
            >


            <!-- PHONE 2 -->

            <label class="form-label">
                Phone 2 (optional)
            </label>

            <input
                type="text"
                name="phone2"
                class="form-control mb-3"
                value="<?= h($user['phone2'] ?? '') ?>"
                placeholder="Secondary phone number"
                maxlength="10"
                pattern="[0-9]{10}"
            >


            <!-- PASSWORD -->

            <label class="form-label">
                New Password (optional)
            </label>

            <input
                type="password"
                name="password"
                class="form-control mb-3"
                placeholder="Leave blank to keep same"
                minlength="8"
            >


            <!-- UPDATE BUTTON -->

            <button
                type="submit"
                name="update"
                value="1"
                class="btn btn-success w-100"
            >

                Update User

            </button>

        </form>


        <hr class="my-4">


        <!-- =================================================
             DELETE FORM
        ================================================== -->

        <form
            method="POST"
            onsubmit="return confirmOwnerDelete();"
        >

            <?= csrfField() ?>


            <?php if (
                ($user['role'] ?? '') ===
                'owner'
            ): ?>

                <div
                    class="alert alert-warning"
                >

                    <strong>
                        Warning
                    </strong>

                    <br>

                    Deleting this Owner will permanently
                    remove the Owner's assigned lifts and
                    their applicable Service Request,
                    Offer, Payment, SAF/PAT and maintenance
                    workflow records.

                    <br><br>

                    This action cannot be undone.

                </div>

            <?php else: ?>

                <div
                    class="alert alert-warning"
                >

                    This will permanently delete this
                    user account.

                </div>

            <?php endif; ?>


            <button
                type="submit"
                name="delete_user"
                value="1"
                class="btn btn-danger w-100"
            >

                Delete User

            </button>

        </form>


    </div>

</div>


<script>

function confirmOwnerDelete()
{
    return confirm(
        "WARNING!\n\n" +
        "Are you sure you want to permanently delete this user?\n\n" +
        "If this is an Owner, the system will remove only records belonging to that Owner, including assigned lifts and applicable Service Request, Offer, Payment, SAF/PAT and maintenance workflow records.\n\n" +
        "This action cannot be undone.\n\n" +
        "Click OK to continue."
    );
}

</script>


<?php include('../includes/footer.php'); ?>