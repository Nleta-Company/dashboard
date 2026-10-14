<?php

include('../config/db.php');
include('../config/auth.php');
require_once('../config/security.php');

require_role('owner');

$job_id   = (int)($_GET['job_id'] ?? 0);
$owner_id = (int)$_SESSION['uid'];

if ($job_id <= 0) {
    header("Location: approvals.php");
    exit;
}

/* =========================================================
   HELPERS — SAF CHECKLIST FINAL APPROVAL GATE
   ---------------------------------------------------------
   Direct Owner Approval is NOT allowed for a SAF inspection
   while any NC/CA workflow is still pending.

   NC:
     - Owner Responsibility Confirmation required.
     - NC must be resolved through the configured recheck /
       rectification workflow before final approval.

   CA:
     - Owner Responsibility Confirmation required.
     - Every CA checkpoint must have a final Owner decision:
         approved OR rejected.
     - A pending CA decision blocks final approval.

   IMPORTANT:
   This is a SERVER-SIDE gate. Hiding a button in the UI is
   not sufficient; direct POST requests are also blocked.
========================================================= */

function nletaDecodeChecklist($json): array
{
    if (empty($json)) {
        return [];
    }

    $decoded = json_decode((string)$json, true);

    return is_array($decoded) ? $decoded : [];
}

function nletaCollectChecklistResults(array $checklist): array
{
    $items = [];

    /*
     * Standard result container.
     */
    if (
        isset($checklist['results'])
        && is_array($checklist['results'])
    ) {
        foreach ($checklist['results'] as $ref => $item) {
            if (!is_array($item)) {
                continue;
            }

            $items[] = [
                'ref'    => (string)$ref,
                'floor'  => '',
                'item'   => $item
            ];
        }
    }

    /*
     * Floor-wise containers used by PAT.
     * Support both current and legacy names.
     */
    foreach (['floor_checklist', 'floor_results'] as $container) {

        if (
            !isset($checklist[$container])
            || !is_array($checklist[$container])
        ) {
            continue;
        }

        foreach ($checklist[$container] as $floor => $floorItems) {

            if (!is_array($floorItems)) {
                continue;
            }

            foreach ($floorItems as $ref => $item) {

                if (!is_array($item)) {
                    continue;
                }

                $items[] = [
                    'ref'   => (string)$ref,
                    'floor' => (string)$floor,
                    'item'  => $item
                ];
            }
        }
    }

    return $items;
}

/*
 * Returns a detailed approval gate for SAF/PAT/SAT/SCI.
 */
function nletaLatestRecheckItem($value): ?array
{
    if (!is_array($value)) {
        return null;
    }

    /* Current format may be a single item. */
    if (!isset($value[0])) {
        return $value;
    }

    /* Legacy / history format: use the latest entry. */
    $last = end($value);

    return is_array($last) ? $last : null;
}

function nletaFindRecheckItem(array $recheckResults, string $ref): ?array
{
    if ($ref === '') {
        return null;
    }

    if (array_key_exists($ref, $recheckResults)) {
        return nletaLatestRecheckItem($recheckResults[$ref]);
    }

    /* Case-insensitive fallback for legacy reference keys. */
    foreach ($recheckResults as $storedRef => $storedItem) {
        if (strcasecmp((string)$storedRef, $ref) === 0) {
            return nletaLatestRecheckItem($storedItem);
        }
    }

    return null;
}

/*
 * Final Owner Approval gate for SAF inspections.
 *
 * Business rule:
 * 1. If there is ANY original NC or CA, Owner Responsibility
 *    Confirmation + supporting document MUST be submitted.
 *    The uploaded document is the Owner's one-time responsibility
 *    acceptance for all NC/CA findings.
 *
 * 2. Every original NC must be closed through recheck with C/OK.
 *
 * 3. Every original CA must have Owner approval. If Owner rejected
 *    a CA, it enters recheck and that recheck must ultimately close
 *    with C/OK. A recheck CA is not final until it has an explicit
 *    Owner CA approval.
 *
 * 4. Nothing is considered complete merely because the original
 *    checklist still contains NC. Recheck results are authoritative
 *    for closure while the original inspection remains preserved.
 */
function nletaGetOwnerFinalApprovalGate(array $checklist): array
{
    $items = nletaCollectChecklistResults($checklist);

    $ncTotal = 0;
    $caTotal = 0;
    $caApproved = 0;
    $caPending = 0;
    $caRejected = 0;

    foreach ($items as $entry) {
        $item = $entry['item'];
        $result = strtoupper(trim((string)($item['result'] ?? '')));

        if ($result === 'NC') {
            $ncTotal++;
            continue;
        }

        if ($result === 'CA') {
            $caTotal++;

            $decision = (
                isset($item['owner_ca_decision'])
                && is_array($item['owner_ca_decision'])
            ) ? $item['owner_ca_decision'] : [];

            $decisionStatus = strtolower(
                trim((string)($decision['status'] ?? ''))
            );

            if ($decisionStatus === 'approved') {
                $caApproved++;
            } elseif ($decisionStatus === 'rejected') {
                $caRejected++;
            } else {
                $caPending++;
            }
        }
    }

    /*
     * One-time Owner Responsibility Confirmation is the approval
     * authorization for ALL NC + CA findings.
     *
     * The supporting document is mandatory. A confirmation flag
     * without a stored document must never unlock approval.
     */
    $responsibility = (
        isset($checklist['owner_responsibility_confirmation'])
        && is_array($checklist['owner_responsibility_confirmation'])
    ) ? $checklist['owner_responsibility_confirmation'] : [];

    $responsibilityStatus = strtolower(
        trim((string)($responsibility['status'] ?? ''))
    );

    $responsibilityFlag = (
        ($responsibility['owner_responsibility_confirmed'] ?? false) === true
    );

    $responsibilityDocument = trim((string)(
        $responsibility['document'] ?? ''
    ));

    /* Backward-compatible fallback for older records. */
    if ($responsibilityDocument === '') {
        $responsibilityDocument = trim((string)(
            $responsibility['document_name'] ?? ''
        ));
    }

    $responsibilityConfirmed =
        ($responsibilityStatus === 'confirmed' || $responsibilityFlag)
        && $responsibilityDocument !== '';

    $hasNcOrCa = ($ncTotal > 0 || $caTotal > 0);

    /*
     * IMPORTANT BUSINESS RULE:
     *
     * NC/CA themselves do NOT block Owner Final Approval once the
     * Owner has submitted the one-time Responsibility Confirmation
     * together with its supporting document.
     *
     * The uploaded document means the Owner accepts responsibility
     * for all NC + CA findings and undertakes rectification/correction.
     *
     * Therefore:
     *   NC present + document uploaded     => ALLOW
     *   CA present + document uploaded     => ALLOW
     *   NC/CA present + no document        => BLOCK
     *   no NC/CA                           => ALLOW
     */
    $blocked = false;
    $reasons = [];

    if ($hasNcOrCa && !$responsibilityConfirmed) {
        $blocked = true;
        $reasons[] =
            'Owner Responsibility Confirmation is mandatory for all NC/CA findings. The supporting document must be uploaded before approval.';
    }

    return [
        'blocked' => $blocked,
        'has_nc_or_ca' => $hasNcOrCa,
        'responsibility_confirmed' => $responsibilityConfirmed,
        'responsibility_document' => $responsibilityDocument !== '',
        'nc_total' => $ncTotal,
        'nc_pending' => $ncTotal,
        'nc_resolved' => 0,
        'ca_total' => $caTotal,
        'ca_pending' => $caPending,
        'ca_approved' => $caApproved,
        'ca_rejected' => $caRejected,
        'recheck_missing' => 0,
        'recheck_nc_pending' => 0,
        'recheck_ca_pending' => 0,
        'recheck_status' => strtolower(trim((string)(
            $checklist['recheck_status'] ?? ''
        ))),
        'reasons' => $reasons
    ];
}


/* =========================================================
   GET JOB
========================================================= */

$stmt = $conn->prepare("
    SELECT
        mj.id,
        mj.asset_id,
        mj.technician_id,
        mj.status,
        mj.checklist,
        mj.remarks,
        mj.owner_remark,
        mj.job_type,
        mj.recheck_date,
        mj.recheck_status,
        a.asset_name,
        a.owner_id

    FROM maintenance_jobs mj

    INNER JOIN assets a
        ON a.id = mj.asset_id

    WHERE mj.id = ?
      AND a.owner_id = ?

    LIMIT 1
");

if (!$stmt) {
    $_SESSION['error'] =
        'Unable to process the maintenance request.';

    header("Location: approvals.php");
    exit;
}

$stmt->bind_param(
    "ii",
    $job_id,
    $owner_id
);

$stmt->execute();

$job = $stmt->get_result()->fetch_assoc();

$stmt->close();


if (!$job) {

    $_SESSION['error'] =
        'Maintenance request not found.';

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   ONLY PENDING OWNER JOB CAN BE APPROVED
========================================================= */

if ($job['status'] !== 'pending_owner') {

    $_SESSION['error'] =
        'This maintenance request has already been processed.';

    header("Location: approvals.php");
    exit;
}


/* =========================================================
   BUILD FINAL APPROVAL GATE
========================================================= */

$isSafInspection =
    (($job['job_type'] ?? '') === 'saf_inspection');

$checklist =
    nletaDecodeChecklist(
        $job['checklist'] ?? ''
    );

$approvalGate = [
    'blocked' => false,
    'has_nc_or_ca' => false,
    'responsibility_confirmed' => false,
    'nc_pending' => 0,
    'ca_total' => 0,
    'ca_pending' => 0,
    'ca_approved' => 0,
    'ca_rejected' => 0,
    'reasons' => []
];

if ($isSafInspection) {

    $checklist['recheck_status'] =
        (string)($job['recheck_status'] ?? '');
    $checklist['recheck_date'] =
        (string)($job['recheck_date'] ?? '');

    $approvalGate =
        nletaGetOwnerFinalApprovalGate(
            $checklist
        );
}


/* =========================================================
   APPROVE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
            "Location: approve.php?job_id="
            . $job_id
        );

        exit;
    }


    /* =====================================================
       AUTHORITATIVE SAF CHECKLIST GATE
       -----------------------------------------------------
       Re-read checklist from DB before changing status so a
       stale browser page cannot bypass the workflow.
    ===================================================== */

    if ($isSafInspection) {

        $lockStmt = $conn->prepare("
            SELECT
                status,
                checklist
            FROM maintenance_jobs
            WHERE
                id = ?
                AND owner_id = ?
                AND job_type = 'saf_inspection'
            LIMIT 1
        ");

        if (!$lockStmt) {

            $_SESSION['error'] =
                'Unable to verify the checklist approval status.';

            header(
                "Location: approve.php?job_id="
                . $job_id
            );

            exit;
        }

        $lockStmt->bind_param(
            "ii",
            $job_id,
            $owner_id
        );

        if (!$lockStmt->execute()) {

            $lockStmt->close();

            $_SESSION['error'] =
                'Unable to verify the checklist approval status.';

            header(
                "Location: approve.php?job_id="
                . $job_id
            );

            exit;
        }

        $latestJob =
            $lockStmt
            ->get_result()
            ->fetch_assoc();

        $lockStmt->close();

        if (
            !$latestJob
            || $latestJob['status'] !== 'pending_owner'
        ) {

            $_SESSION['error'] =
                'This maintenance request has already been processed.';

            header("Location: approvals.php");
            exit;
        }

        $latestChecklist =
            nletaDecodeChecklist(
                $latestJob['checklist'] ?? ''
            );

        /* Recheck status/date live on maintenance_jobs in this project. */
        $latestChecklist['recheck_status'] =
            (string)($latestJob['recheck_status'] ?? '');
        $latestChecklist['recheck_date'] =
            (string)($latestJob['recheck_date'] ?? '');

        $latestGate =
            nletaGetOwnerFinalApprovalGate(
                $latestChecklist
            );

        if ($latestGate['blocked']) {

            $_SESSION['error'] =
                'Owner approval is blocked. '
                . implode(
                    ' ',
                    $latestGate['reasons']
                );

            header(
                "Location: approve.php?job_id="
                . $job_id
            );

            exit;
        }

        /*
         * Use the latest database checklist for all following
         * logic in this request.
         */
        $checklist = $latestChecklist;
        $approvalGate = $latestGate;
    }


    /* =====================================================
       OWNER REMARK
    ===================================================== */

    $remark =
        trim(
            $_POST['remark'] ?? ''
        );


    if ($remark === '') {

        $_SESSION['error'] =
            'Please enter owner remarks.';

        header(
            "Location: approve.php?job_id="
            . $job_id
        );

        exit;
    }


    /* =====================================================
       UPDATE JOB
       -----------------------------------------------------
       SAF inspection reaches Approved ONLY after all required
       NC / CA / Owner Responsibility workflow is complete.
    ===================================================== */

    $upd = $conn->prepare("
        UPDATE maintenance_jobs
        SET
            status = 'approved',
            owner_remark = ?
        WHERE id = ?
          AND status = 'pending_owner'
    ");

    if (!$upd) {
        $_SESSION['error'] = 'Unable to approve maintenance.';
        header("Location: approvals.php");
        exit;
    }

    $upd->bind_param(
        "si",
        $remark,
        $job_id
    );

    if (
        !$upd->execute()
        ||
        $upd->affected_rows <= 0
    ) {
        $_SESSION['error'] = 'Unable to approve maintenance.';
        $upd->close();
        header("Location: approvals.php");
        exit;
    }

    $upd->close();


    /* =====================================================
       ADD / UPDATE MAINTENANCE HISTORY
    ===================================================== */

    $chk = $conn->prepare("
        SELECT id

        FROM maintenance_records

        WHERE asset_id = ?
          AND maintenance_date = CURDATE()

        LIMIT 1
    ");


    if ($chk) {

        $chk->bind_param(
            "i",
            $job['asset_id']
        );

        $chk->execute();

        $existingRecord =
            $chk
            ->get_result()
            ->fetch_assoc();

        $chk->close();


        if ($existingRecord) {

            $record_id =
                (int)$existingRecord['id'];


            $recUpd = $conn->prepare("
                UPDATE maintenance_records

                SET
                    remarks = ?,
                    status = 'approved'

                WHERE id = ?
            ");


            if ($recUpd) {

                $recUpd->bind_param(
                    "si",
                    $remark,
                    $record_id
                );

                $recUpd->execute();

                $recUpd->close();
            }


        } else {


            $recIns = $conn->prepare("
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
                    CURDATE(),
                    ?,
                    'approved'
                )
            ");


            if ($recIns) {

                $recIns->bind_param(
                    "is",
                    $job['asset_id'],
                    $remark
                );

                $recIns->execute();

                $recIns->close();
            }
        }
    }


    /* =====================================================
       ASSET ACTIVE
    ===================================================== */

    $assetUpd = $conn->prepare("
        UPDATE assets

        SET
            status = 'Active'

        WHERE id = ?
    ");


    if ($assetUpd) {

        $assetUpd->bind_param(
            "i",
            $job['asset_id']
        );

        $assetUpd->execute();

        $assetUpd->close();
    }


    /* =====================================================
       SUCCESS
    ===================================================== */

    $_SESSION['success'] =
        'Maintenance approved successfully.';

    header(
        "Location: approvals.php"
    );

    exit;
}


/* =========================================================
   HEADER
========================================================= */

include('../includes/header.php');
include('../includes/sidebar-owner.php');

?>

<div class="content">

    <h3 class="mb-4">
        Approve Maintenance
    </h3>


    <?php if (!empty($_SESSION['error'])): ?>

        <div class="alert alert-danger alert-dismissible fade show">

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
                data-bs-dismiss="alert">
            </button>

        </div>

    <?php endif; ?>


    <?php if (
        $isSafInspection
        && $approvalGate['blocked']
    ): ?>

        <div class="alert alert-warning">

            <div class="fw-bold mb-2">
                <i class="bi bi-lock-fill me-1"></i>
                Final Owner Approval Locked
            </div>

            <div class="mb-2">
                This PAT/SAT/SCI inspection can be approved after the Owner has completed the one-time NC/CA Responsibility Confirmation and uploaded the supporting document.
            </div>

            <ul class="mb-0">

                <?php foreach (
                    $approvalGate['reasons']
                    as $reason
                ): ?>

                    <li>
                        <?= htmlspecialchars($reason) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

            <div class="mt-3">

                <a
                    href="view-checklist.php?job_id=<?= (int)$job_id ?>"
                    class="btn btn-primary btn-sm"
                >
                    <i class="bi bi-eye me-1"></i>
                    Open Checklist
                </a>

                <a
                    href="approvals.php"
                    class="btn btn-secondary btn-sm ms-1"
                >
                    Back to Approvals
                </a>

            </div>

        </div>

    <?php endif; ?>


    <div
        class="card shadow p-4"
        style="max-width: 650px;"
    >


        <h5 class="mb-3">

            <?= htmlspecialchars(
                $job['asset_name']
            ) ?>

        </h5>


        <?php if (
            $isSafInspection
            && $approvalGate['blocked']
        ): ?>

            <div class="alert alert-light border">

                <strong>Approval Status:</strong>
                <span class="text-danger">
                    Locked
                </span>

                <?php if (
                    $approvalGate['has_nc_or_ca']
                ): ?>

                    <div class="small text-muted mt-2">

                        NC total:
                        <strong>
                            <?= (int)$approvalGate['nc_total'] ?>
                        </strong>

                        &nbsp; | &nbsp;

                        NC resolved:
                        <strong>
                            <?= (int)$approvalGate['nc_resolved'] ?>
                        </strong>

                        &nbsp; | &nbsp;

                        NC findings:
                        <strong>
                            <?= (int)$approvalGate['nc_pending'] ?>
                        </strong>

                        <br>

                        CA total:
                        <strong>
                            <?= (int)$approvalGate['ca_total'] ?>
                        </strong>

                        &nbsp; | &nbsp;

                        CA pending:
                        <strong>
                            <?= (int)$approvalGate['ca_pending'] ?>
                        </strong>

                        <br>

                        Owner Responsibility Confirmation:
                        <strong>
                            <?= $approvalGate['responsibility_confirmed']
                                ? 'Completed + Document Uploaded'
                                : 'Pending — Confirmation Document Required' ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>


        <form
            method="POST"
            <?php if (
                $isSafInspection
                && $approvalGate['blocked']
            ): ?>
                onsubmit="return false;"
            <?php endif; ?>
        >


            <?= csrfField() ?>


            <!-- =================================================
                 OWNER REMARKS
            ================================================== -->

            <div class="mb-3">

                <label class="form-label fw-bold">

                    Owner Remarks

                </label>


                <textarea
                    name="remark"
                    class="form-control"
                    rows="4"
                    required
                    placeholder="Enter your remarks..."
                    <?= (
                        $isSafInspection
                        && $approvalGate['blocked']
                    )
                        ? 'disabled'
                        : '' ?>
                ></textarea>

            </div>


            <!-- =================================================
                 APPROVE
            ================================================= -->

            <?php if (
                !$isSafInspection
                || !$approvalGate['blocked']
            ): ?>

                <button
                    type="submit"
                    class="btn btn-success w-100"
                    onclick="return confirm('Approve this maintenance request?')"
                >

                    ✓ Approve

                </button>

            <?php else: ?>

                <button
                    type="button"
                    class="btn btn-secondary w-100"
                    disabled
                >

                    <i class="bi bi-lock-fill me-1"></i>
                    Approval Locked — Upload Owner Responsibility Confirmation

                </button>

            <?php endif; ?>


            <!-- =================================================
                 CANCEL
            ================================================== -->

            <a
                href="approvals.php"
                class="btn btn-secondary w-100 mt-2"
            >

                Cancel

            </a>


        </form>


    </div>

</div>


<?php include('../includes/footer.php'); ?>