<?php

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
require_once __DIR__ . '/../config/security.php';

$owner_id = (int)$_SESSION['uid'];

/* =========================================================
   SAF CHECKLIST NC DETECTION
   ---------------------------------------------------------
   Actual checklist "result" values ko check karta hai.
   Fixed + floor-based checklist dono cover honge.
========================================================= */


/* =========================================================
   BEFORE FINAL CHECKLIST PAYMENT — OWNER VIEW UNLOCK
   ---------------------------------------------------------
   SAF inspection checklist can be viewed only after the
   Owner's "Before Final Checklist" payment is verified.

   Technician Monthly Maintenance is NOT affected.
========================================================= */

function isBeforeFinalChecklistPaid(mysqli $conn, int $offerId): bool
{
    if ($offerId <= 0) {
        return false;
    }

    /*
     * A stage is considered paid/unlocked when:
     * 1) payment_schedule says Paid/Verified, OR
     * 2) a linked payment_transactions row is Verified.
     *
     * This supports both current and older synchronized records.
     */
    $stmt = $conn->prepare("
        SELECT 1
        FROM payment_schedule ps
        WHERE
            ps.Offer_ID = ?
            AND ps.Payment_Stage = 'Before Final Checklist'
            AND (
                LOWER(TRIM(COALESCE(ps.Status, ''))) IN ('paid', 'verified')
                OR EXISTS (
                    SELECT 1
                    FROM payment_transactions pt
                    WHERE
                        pt.Schedule_ID = ps.Schedule_ID
                        AND LOWER(TRIM(COALESCE(pt.Status, ''))) = 'verified'
                )
            )
        LIMIT 1
    ");

    if (!$stmt) {
        /*
         * Do not fail-open if the payment query cannot be prepared.
         * The checklist remains locked.
         */
        return false;
    }

    $stmt->bind_param('i', $offerId);

    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();
    $paid = $result && (bool)$result->fetch_assoc();

    $stmt->close();

    return $paid;
}



/* =========================================================
   CHECKLIST APPROVALS — PERMANENT UNLOCK
========================================================= */

$checklistApprovalHistory = false;

$historyStmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM maintenance_jobs mj
    INNER JOIN assets a
        ON a.id = mj.asset_id
    WHERE
        a.owner_id = ?
        AND mj.job_type = 'saf_inspection'
");

if ($historyStmt) {

    $historyStmt->bind_param(
        "i",
        $owner_id
    );

    if ($historyStmt->execute()) {

        $historyRow =
            $historyStmt
            ->get_result()
            ->fetch_assoc();

        $checklistApprovalHistory =
            (
                (int)(
                    $historyRow['total']
                    ?? 0
                ) > 0
            );
    }

    $historyStmt->close();
}


/*
 * Keep original Owner access controller for Owners
 * who have never received an SAF checklist.
 */
if (!$checklistApprovalHistory) {

    requireOwnerAccess('approvals');
}


/* =========================================================
   PSGA AGREEMENTS ARE HANDLED IN OWNER OFFERS
   ---------------------------------------------------------
   PSGA is intentionally NOT part of Checklist Approvals.
   Admin-sent PSGA approvals are shown in offers.php.
========================================================= */

/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');

include('../includes/sidebar-owner.php');


/* =========================================================
   GET SAF INSPECTION CHECKLIST APPROVALS ONLY
   ---------------------------------------------------------
   Normal Technician/AMC maintenance checklists are shown
   in the separate Owner Maintenance Checklist section.
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.checklist,
        mj.remarks,
        mj.file,
        mj.created_at,
        mj.status,
        mj.job_type,
        mj.saf_id,

        s.offer_id AS saf_offer_id,

        a.asset_name

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    LEFT JOIN saf_contracts s
        ON s.id = mj.saf_id
        AND mj.job_type = 'saf_inspection'

    WHERE
        a.owner_id = ?

        /*
         * IMPORTANT:
         * This page is ONLY for SAF / PAT / SAT / SCI
         * inspection checklist approvals.
         *
         * Normal Technician/AMC maintenance checklists
         * are handled by owner/maintenance-checklist.php.
         */
        AND LOWER(
            TRIM(
                COALESCE(
                    mj.job_type,
                    ''
                )
            )
        ) = 'saf_inspection'

    ORDER BY
        mj.created_at DESC
");


if (!$stmt) {

    die('Unable to load checklist approvals: '
        . htmlspecialchars(
            $conn->error
        ));
}


$stmt->bind_param(
    "i",
    $owner_id
);


$stmt->execute();


$q =
    $stmt->get_result();


/* =========================================================
   STATUS BADGE
========================================================= */

function getStatusBadge($status)
{
    switch ($status) {

        case 'pending_owner':

            return '
                <span class="badge bg-info text-dark">
                    Pending Approval
                </span>
            ';


        case 'approved':

            return '
                <span class="badge bg-success">
                    Approved
                </span>
            ';


        case 'rejected':

            return '
                <span class="badge bg-danger">
                    Rejected
                </span>
            ';


        default:

            return '
                <span class="badge bg-secondary">
                    '
                . htmlspecialchars(
                    ucfirst(
                        str_replace(
                            '_',
                            ' ',
                            $status
                        )
                    )
                )
                . '
                </span>
            ';
    }
}

?>

<div class="content approval-page">

    <style>
        /* =========================================================
   APPROVALS — RESPONSIVE DASHBOARD TILES
   Desktop: 3 columns
   Tablet : 2 columns
   Mobile : 1 column
   No horizontal scrolling
========================================================= */

        .approval-page {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 20px;
            overflow-x: hidden;
        }

        .approval-page h3 {
            margin: 0 0 18px;
            font-weight: 700;
            color: #172033;
        }

        .approval-table-wrap {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            overflow: visible !important;
            background: transparent;
            border: 0;
            box-shadow: none;
        }

        /* =========================================================
   GRID
========================================================= */

        .approval-table {
            display: block;
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            margin: 0 !important;
            border: 0 !important;
            border-collapse: separate !important;
            border-spacing: 0 !important;
            table-layout: fixed !important;
        }

        .approval-table thead {
            display: none;
        }

        .approval-table tbody {
            display: grid !important;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            margin: 0;
            padding: 0;
        }

        .approval-table tbody tr {
            position: relative;
            display: flex !important;
            flex-direction: column;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            min-height: 205px;
            margin: 0 !important;
            padding: 0;
            box-sizing: border-box;
            background: #fff;
            border: 1px solid #e3e8ef;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(15, 23, 42, .07);
            overflow: hidden;
            transition: transform .15s ease, box-shadow .15s ease;
        }

        .approval-table tbody tr:hover {
            background: #fff;
            transform: translateY(-2px);
            box-shadow: 0 7px 18px rgba(15, 23, 42, .11);
        }

        /* Colored card top border */
        .approval-table tbody tr:nth-child(4n+1) {
            border-top: 3px solid #2f80ed;
        }

        .approval-table tbody tr:nth-child(4n+2) {
            border-top: 3px solid #20c997;
        }

        .approval-table tbody tr:nth-child(4n+3) {
            border-top: 3px solid #f5a623;
        }

        .approval-table tbody tr:nth-child(4n+4) {
            border-top: 3px solid #ff4d6d;
        }

        /* =========================================================
   CARD CONTENT
========================================================= */

        .approval-table tbody td {
            display: block;
            width: 100%;
            max-width: 100%;
            min-width: 0;
            box-sizing: border-box;
            padding: 8px 12px;
            border: 0 !important;
            color: #273449;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .approval-table tbody td:nth-child(1) {
            padding: 12px 105px 5px 12px;
            font-size: 15px;
            font-weight: 700;
        }

        .approval-lift {
            display: block;
            max-width: 100%;
            color: #172033;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        /* Status badge */
        .approval-table tbody td:nth-child(2) {
            position: absolute;
            top: 9px;
            right: 10px;
            width: auto;
            max-width: calc(100% - 120px);
            padding: 0;
            z-index: 2;
        }

        .approval-table tbody td:nth-child(2) .badge {
            display: inline-block;
            max-width: 100%;
            font-size: 10px;
            padding: 5px 7px;
            border-radius: 5px;
            white-space: normal;
            text-align: center;
        }

        /* Date */
        .approval-table tbody td:nth-child(3) {
            padding-top: 3px;
            padding-bottom: 5px;
            font-size: 11px;
            color: #667085;
        }

        /* Checklist */
        .approval-table tbody td:nth-child(4) {
            padding-top: 4px;
            padding-bottom: 4px;
        }

        .checklist-submitted {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            max-width: 100%;
            padding: 5px 8px;
            border-radius: 6px;
            background: #eef4ff;
            color: #315fa8;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Remarks */
        .approval-table tbody td:nth-child(5) {
            flex: 1 1 auto;
            min-height: 28px;
            padding-top: 5px;
            padding-bottom: 5px;
            font-size: 11px;
            color: #667085;
        }

        /* File */
        .approval-table tbody td:nth-child(6) {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            min-height: 28px;
            padding-top: 4px;
            padding-bottom: 4px;
            font-size: 11px;
        }

        .approval-table tbody td:nth-child(6) .btn {
            margin: 0 !important;
            font-size: 10px;
            padding: 4px 7px;
        }

        /* Bottom actions */
        .approval-table tbody td:nth-child(7) {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 5px;
            margin-top: auto;
            padding: 9px 12px 11px;
            border-top: 1px solid #edf0f4 !important;
            background: #fafbfc;
        }

        .approval-actions {
            min-width: 0 !important;
            max-width: 100%;
        }

        .approval-actions .btn {
            margin: 0 !important;
            max-width: 100%;
            font-size: 10px;
            line-height: 1.2;
            padding: 6px 8px;
            border-radius: 6px;
            white-space: nowrap;
        }

        .approval-actions .text-warning {
            width: 100%;
            font-size: 9px;
            line-height: 1.25;
            margin: 0 !important;
        }

        .approval-actions .text-success,
        .approval-actions .text-danger {
            font-size: 11px;
        }

        .view-checklist-btn {
            min-width: 0;
            border-radius: 6px;
            font-weight: 600;
        }

        /* Empty state */
        .approval-empty {
            display: block !important;
            grid-column: 1 / -1;
            width: 100% !important;
            min-height: auto !important;
            padding: 35px 20px !important;
            text-align: center !important;
            border: 1px solid #e3e8ef !important;
            border-radius: 12px !important;
            background: #fff !important;
        }

        /* =========================================================
   TABLET — 2 COLUMNS
========================================================= */

        @media (min-width: 768px) and (max-width: 1199px) {

            .approval-page {
                padding: 16px;
            }

            .approval-page h3 {
                font-size: 24px;
                margin-bottom: 16px;
            }

            .approval-table tbody {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .approval-table tbody tr {
                min-height: 205px;
            }
        }

        /* =========================================================
   MOBILE — 1 COLUMN
========================================================= */

        @media (max-width: 767px) {

            .approval-page {
                padding: 10px;
            }

            .approval-page h3 {
                font-size: 21px;
                margin: 2px 0 14px;
            }

            .approval-table tbody {
                grid-template-columns: minmax(0, 1fr);
                gap: 10px;
            }

            .approval-table tbody tr {
                min-height: 0;
                border-radius: 11px;
            }

            .approval-table tbody td {
                padding-left: 11px;
                padding-right: 11px;
            }

            .approval-table tbody td:nth-child(1) {
                padding-top: 11px;
                padding-bottom: 5px;
                padding-right: 105px;
            }

            .approval-table tbody td:nth-child(2) {
                top: 8px;
                right: 9px;
            }

            .approval-table tbody td:nth-child(3) {
                padding-top: 3px;
            }

            .approval-table tbody td:nth-child(5) {
                min-height: 25px;
            }

            .approval-table tbody td:nth-child(7) {
                padding: 8px 11px 10px;
            }

            .approval-actions .btn {
                font-size: 10px;
                padding: 6px 7px;
            }
        }

        /* =========================================================
   VERY SMALL MOBILE
========================================================= */

        @media (max-width: 420px) {

            .approval-page {
                padding: 8px;
            }

            .approval-page h3 {
                font-size: 19px;
            }

            .approval-table tbody {
                gap: 9px;
            }

            .approval-table tbody td:nth-child(1) {
                font-size: 14px;
            }

            .approval-table tbody td:nth-child(2) .badge {
                font-size: 9px;
                padding: 4px 6px;
            }

            .approval-table tbody td:nth-child(3),
            .approval-table tbody td:nth-child(5),
            .approval-table tbody td:nth-child(6) {
                font-size: 10px;
            }

            .approval-actions .btn {
                font-size: 9px;
                padding: 5px 6px;
            }
        }

        /* =========================================================
   NO HORIZONTAL SCROLL
========================================================= */

        html,
        body {
            max-width: 100%;
            overflow-x: hidden !important;
        }

        .approval-page,
        .approval-table-wrap,
        .approval-table,
        .approval-table tbody,
        .approval-table tbody tr,
        .approval-table tbody td {
            max-width: 100%;
            min-width: 0 !important;
            box-sizing: border-box;
        }

        /* Important for layouts where the main content is a flex item */
        .content.approval-page {
            flex: 1 1 auto;
        }


        /* =========================================================
   FINAL CONTENT-WIDTH FIX
   ---------------------------------------------------------
   The main .content container may already have a sidebar
   offset. Do NOT set the approval page to viewport width,
   otherwise the right side gets clipped.
========================================================= */

        .content.approval-page {
            width: auto !important;
            max-width: none !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
            margin-right: 0 !important;
            overflow-x: hidden !important;
        }

        /* Let the card grid use exactly the available content width. */
        .content.approval-page .approval-table-wrap {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
            overflow: visible !important;
        }

        .content.approval-page .approval-table {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }

        .content.approval-page .approval-table tbody {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            box-sizing: border-box !important;
        }

        .content.approval-page .approval-table tbody tr {
            width: 100% !important;
            max-width: 100% !important;
            min-width: 0 !important;
            box-sizing: border-box !important;
        }

        /* Tablet */
        @media (min-width: 768px) and (max-width: 1199px) {
            .content.approval-page .approval-table tbody {
                grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            }
        }

        /* Mobile */
        @media (max-width: 767px) {
            .content.approval-page {
                width: 100% !important;
                padding-left: 10px;
                padding-right: 10px;
            }

            .content.approval-page .approval-table tbody {
                grid-template-columns: minmax(0, 1fr) !important;
            }
        }

        /* If the application content is a flex child, allow it to shrink. */
        .content.approval-page {
            flex: 1 1 auto !important;
        }
    </style>


    <!-- =========================================================
     PAGE TITLE
========================================================= -->

    <h3>

        Approvals

    </h3>


    <!-- =========================================================
     SUCCESS MESSAGE
========================================================= -->

    <?php if (
        !empty($_SESSION['success'])
    ): ?>

        <div
            class="alert alert-success alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['success']
            ); ?>


            <?php
            unset(
                $_SESSION['success']
            );
            ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
     ERROR MESSAGE
========================================================= -->

    <?php if (
        !empty($_SESSION['error'])
    ): ?>

        <div
            class="alert alert-danger alert-dismissible fade show">

            <?= htmlspecialchars(
                $_SESSION['error']
            ); ?>


            <?php
            unset(
                $_SESSION['error']
            );
            ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
     TABLE
========================================================= -->

    <div
        class="approval-table-wrap">

        <table
            class="table table-hover approval-table">

            <thead class="table-light">

                <tr>

                    <th>
                        Lift
                    </th>


                    <th>
                        Status
                    </th>


                    <th>
                        Date
                    </th>


                    <th>
                        Checklist
                    </th>


                    <th>
                        Remarks
                    </th>


                    <th>
                        File
                    </th>


                    <th width="180">
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>


                <?php if (
                    $q->num_rows === 0
                    &&
                    empty($psgaApprovals)
                ): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="text-center text-muted approval-empty">

                            No SAF checklist approvals found.

                        </td>

                    </tr>

                <?php endif; ?>


                <?php while (
                    $r = $q->fetch_assoc()
                ): ?>


                    <?php

                    /* =========================================================
   DECODE CHECKLIST
========================================================= */

                    $decodedChecklist = [];


                    if (
                        !empty($r['checklist'])
                    ) {

                        $decodedChecklist =
                            json_decode(
                                $r['checklist'],
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


                    $results =
                        is_array(
                            $decodedChecklist['results'] ?? null
                        )
                        ? $decodedChecklist['results']
                        : [];


                    /* =========================================================
   ACTION PAYMENT LOCK
   ---------------------------------------------------------
   SAF/PAT/SAT/SCI:
       Before Final Checklist payment verified -> UNLOCK
       Otherwise -> LOCK

   Technician Monthly Maintenance:
       NOT affected by this rule.
========================================================= */

                    $isSafInspection =
                        (($r['job_type'] ?? '') === 'saf_inspection');

                    $safOfferId =
                        (int)($r['saf_offer_id'] ?? 0);

                    $beforeFinalChecklistPaid =
                        false;

                    if ($isSafInspection) {
                        $beforeFinalChecklistPaid =
                            isBeforeFinalChecklistPaid(
                                $conn,
                                $safOfferId
                            );
                    }

                    ?>

                    <tr>


                        <!-- =====================================================
     LIFT
====================================================== -->

                        <td>

                            <span
                                class="approval-lift">

                                <?= htmlspecialchars(
                                    $r['asset_name']
                                ) ?>

                            </span>

                        </td>


                        <!-- =====================================================
     STATUS
====================================================== -->

                        <td>

                            <?= getStatusBadge(
                                $r['status']
                            ) ?>

                        </td>


                        <!-- =====================================================
     DATE + IST TIME
====================================================== -->

                        <td>

                            <?php

                            if (
                                !empty($r['created_at'])
                            ) {

                                try {

                                    $createdDate =
                                        new DateTime(
                                            $r['created_at'],
                                            new DateTimeZone(
                                                'UTC'
                                            )
                                        );


                                    $createdDate->setTimezone(
                                        new DateTimeZone(
                                            'Asia/Kolkata'
                                        )
                                    );


                                    $createdDateText =
                                        $createdDate
                                        ->format(
                                            'd M Y'
                                        );


                                    $createdTimeText =
                                        $createdDate
                                        ->format(
                                            'h:i A'
                                        );


                                    echo "
            <small class='text-muted'>
                {$createdDateText}
            </small>
            <br>
            <small class='text-muted'>
                {$createdTimeText}
            </small>
        ";
                                } catch (
                                    Exception $e
                                ) {

                                    echo '—';
                                }
                            } else {

                                echo '—';
                            }

                            ?>

                        </td>


                        <!-- =====================================================
     CHECKLIST
====================================================== -->

                        <td>

                            <span
                                class="checklist-submitted">

                                <i
                                    class="bi bi-clipboard-check"></i>

                                Submitted

                            </span>

                        </td>


                        <!-- =====================================================
     REMARKS
====================================================== -->

                        <td>

                            <?php if (
                                !empty(trim(
                                        $r['remarks']
                                            ?? ''
                                    ))
                            ): ?>

                                <?= nl2br(
                                    htmlspecialchars(
                                        $r['remarks']
                                    )
                                ) ?>

                            <?php else: ?>

                                <span class="text-muted">
                                    —
                                </span>

                            <?php endif; ?>


                            <?php if (
                                $r['status']
                                ===
                                'rejected'
                            ): ?>

                                <div
                                    class="text-danger small mt-1">

                                    Owner rejected this
                                    maintenance.

                                </div>

                            <?php endif; ?>

                        </td>


                        <!-- =====================================================
     FILE — NC EVIDENCE ONLY
====================================================== -->

                        <td>

                            <?php

                            $ncEvidenceRows = [];


                            $evidenceStmt =
                                $conn->prepare("
        SELECT
            ref_code,
            photo_path,
            video_path,
            document_path

        FROM saf_checklist_evidence

        WHERE job_id = ?

        ORDER BY id ASC
    ");


                            if (
                                $evidenceStmt
                            ) {

                                $jobIdForEvidence =
                                    (int)$r['id'];


                                $evidenceStmt
                                    ->bind_param(
                                        "i",
                                        $jobIdForEvidence
                                    );


                                if (
                                    $evidenceStmt
                                    ->execute()
                                ) {

                                    $evResult =
                                        $evidenceStmt
                                        ->get_result();


                                    while (
                                        $ev =
                                        $evResult
                                        ->fetch_assoc()
                                    ) {

                                        $refCode =
                                            (string)(
                                                $ev['ref_code']
                                                ?? ''
                                            );


                                        $isNcRef =
                                            false;


                                        if (
                                            isset(
                                                $results[$refCode]
                                            )
                                            &&
                                            is_array(
                                                $results[$refCode]
                                            )
                                        ) {

                                            $isNcRef =
                                                strtoupper(
                                                    trim(
                                                        (string)(
                                                            $results[$refCode]['result']
                                                            ?? ''
                                                        )
                                                    )
                                                )
                                                ===
                                                'NC';
                                        }


                                        if (
                                            $isNcRef
                                        ) {

                                            $ncEvidenceRows[] =
                                                $ev;
                                        }
                                    }
                                }


                                $evidenceStmt->close();
                            }


                            $hasNcEvidence =
                                false;


                            foreach (
                                $ncEvidenceRows
                                as $ev
                            ) {


                                /* =====================================================
       PHOTO
    ====================================================== */

                                if (
                                    !empty($ev['photo_path'])
                                ) {

                                    $hasNcEvidence =
                                        true;


                                    $photo =
                                        basename(
                                            $ev['photo_path']
                                        );


                                    $photoPath =
                                        __DIR__
                                        . '/../uploads/'
                                        . $photo;


                                    if (
                                        file_exists(
                                            $photoPath
                                        )
                                    ) {

                                        echo '
                <a
                    href="../uploads/'
                                            . rawurlencode(
                                                $photo
                                            )
                                            . '"
                    target="_blank"
                    class="btn btn-outline-primary btn-sm me-1 mb-1"
                >
                    Photo
                </a>
            ';
                                    }
                                }


                                /* =====================================================
       VIDEO
    ====================================================== */

                                if (
                                    !empty($ev['video_path'])
                                ) {

                                    $hasNcEvidence =
                                        true;


                                    $video =
                                        basename(
                                            $ev['video_path']
                                        );


                                    $videoPath =
                                        __DIR__
                                        . '/../uploads/'
                                        . $video;


                                    if (
                                        file_exists(
                                            $videoPath
                                        )
                                    ) {

                                        echo '
                <a
                    href="../uploads/'
                                            . rawurlencode(
                                                $video
                                            )
                                            . '"
                    target="_blank"
                    class="btn btn-outline-primary btn-sm me-1 mb-1"
                >
                    Video
                </a>
            ';
                                    }
                                }


                                /* =====================================================
       DOCUMENT
    ====================================================== */

                                if (
                                    !empty($ev['document_path'])
                                ) {

                                    $hasNcEvidence =
                                        true;


                                    $document =
                                        basename(
                                            $ev['document_path']
                                        );


                                    $documentPath =
                                        __DIR__
                                        . '/../uploads/'
                                        . $document;


                                    if (
                                        file_exists(
                                            $documentPath
                                        )
                                    ) {

                                        echo '
                <a
                    href="../uploads/'
                                            . rawurlencode(
                                                $document
                                            )
                                            . '"
                    target="_blank"
                    class="btn btn-outline-primary btn-sm me-1 mb-1"
                >
                    Document
                </a>
            ';
                                    }
                                }
                            }


                            /* =====================================================
   FALLBACK TO OLD FILE
====================================================== */

                            if (
                                !$hasNcEvidence
                                &&
                                !empty($r['file'])
                            ) {

                                $legacyFile =
                                    basename(
                                        (string)$r['file']
                                    );


                                $filePath =
                                    __DIR__
                                    . '/../uploads/'
                                    . $legacyFile;


                                if (
                                    file_exists(
                                        $filePath
                                    )
                                ) {

                                    echo '
            <a
                href="../uploads/'
                                        . rawurlencode(
                                            $legacyFile
                                        )
                                        . '"
                target="_blank"
                class="btn btn-outline-primary btn-sm"
            >
                View
            </a>
        ';
                                } else {

                                    echo '
            <span
                class="badge bg-warning text-dark"
            >
                File Not Found
            </span>
        ';
                                }
                            } elseif (
                                !$hasNcEvidence
                                &&
                                empty($r['file'])
                            ) {

                                echo '
        <span class="text-muted">
            No file
        </span>
    ';
                            }

                            ?>

                        </td>


                        <!-- =====================================================
     ACTION
====================================================== -->

                        <td
                            class="approval-actions">


                            <!-- =====================================================
     VIEW CHECKLIST
====================================================== -->

                            <?php if (
                                !$isSafInspection
                                ||
                                $beforeFinalChecklistPaid
                            ): ?>

                                <a
                                    href="view-checklist.php?job_id=<?= (int)$r['id'] ?>"
                                    class="btn btn-primary btn-sm mb-1 view-checklist-btn">

                                    <i
                                        class="bi bi-eye me-1"></i>

                                    View Checklist

                                </a>

                            <?php else: ?>

                                <button
                                    type="button"
                                    class="btn btn-secondary btn-sm mb-1 view-checklist-btn"
                                    disabled
                                    title="Before Final Checklist payment is required">

                                    <i
                                        class="bi bi-lock-fill me-1"></i>

                                    View Checklist Locked

                                </button>

                                <div
                                    class="small text-warning fw-semibold mb-1">

                                    <i class="bi bi-credit-card me-1"></i>
                                    Before Final Checklist payment required

                                </div>

                            <?php endif; ?>


                            <?php if (
                                $r['status']
                                ===
                                'pending_owner'
                            ): ?>

                                <?php if (
                                    !$isSafInspection
                                    ||
                                    $beforeFinalChecklistPaid
                                ): ?>

                                    <!-- =============================================
             APPROVE
        ============================================== -->

                                    <a
                                        href="approve.php?job_id=<?= (int)$r['id'] ?>"
                                        class="btn btn-success btn-sm mb-1">

                                        ✓ Approve

                                    </a>


                                    <!-- =============================================
             REJECT
        ============================================== -->

                                    <a
                                        href="reject.php?job_id=<?= (int)$r['id'] ?>"
                                        class="btn btn-danger btn-sm"
                                        onclick="return confirm('Reject this maintenance?')">

                                        ✕ Reject

                                    </a>

                                <?php else: ?>

                                    <!-- =============================================
             SINGLE ACTION LOCK
             ------------------------------------------------
             All checklist actions remain locked until
             Before Final Checklist payment is verified.
        ============================================== -->

                                    <button
                                        type="button"
                                        class="btn btn-secondary btn-sm mb-1 view-checklist-btn"
                                        disabled
                                        title="Before Final Checklist payment is required">

                                        <i class="bi bi-lock-fill me-1"></i>

                                        Action Locked

                                    </button>

                                    <div class="small text-warning fw-semibold mt-1">

                                        <i class="bi bi-credit-card me-1"></i>

                                        Before Final Checklist payment required

                                    </div>

                                <?php endif; ?>


                            <?php elseif (
                                $r['status']
                                ===
                                'approved'
                            ): ?>


                                <span class="text-success fw-bold d-block">
                                    Approved
                                </span>


                            <?php elseif (
                                $r['status']
                                ===
                                'rejected'
                            ): ?>


                                <!-- =============================================
         REJECTED
    ============================================== -->

                                <span
                                    class="text-danger fw-bold d-block">

                                    Rejected

                                </span>

                            <?php endif; ?>


                        </td>


                    </tr>


                <?php endwhile; ?>


            </tbody>

        </table>

    </div>

</div>


<?php include('../includes/footer.php'); ?>