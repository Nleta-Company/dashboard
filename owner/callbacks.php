<?php

include('../config/db.php');
include('../config/auth.php');

require_role('owner');

require_once __DIR__ . '/../config/owner_access.php';
requireOwnerAccess('callback_approval');

include('../includes/header.php');
include('../includes/sidebar-owner.php');

$owner_id = (int)$_SESSION['uid'];


/* =========================================================
   GET OWNER CALLBACKS
========================================================= */

$stmt = $conn->prepare("
    SELECT
        c.id,
        c.callback_number,
        c.issue,
        c.status,

        c.technician_response,
        c.technician_reject_reason,
        c.technician_responded_at,

        c.work_report,
        c.work_report_file,
        c.checklist,

        c.work_started_at,
        c.work_closed_at,
        c.approved_at,

        a.asset_name,
        a.status AS lift_status,

        u.name AS technician_name

    FROM callbacks c

    LEFT JOIN assets a
        ON a.id = c.asset_id

    LEFT JOIN users u
        ON u.id = c.technician_id

    WHERE
         a.owner_id = ?

    ORDER BY c.created_at DESC
");

$stmt->bind_param("i", $owner_id);

$stmt->execute();

$q = $stmt->get_result();

?>

<div class="content">

  <h3 class="mb-4">Callback for Approval</h3>


  <!-- =====================================================
     SUCCESS MESSAGE
===================================================== -->

  <?php if (!empty($_SESSION['success'])): ?>

    <div class="alert alert-success alert-dismissible fade show">

      <?= htmlspecialchars($_SESSION['success']); ?>

      <?php unset($_SESSION['success']); ?>

      <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
      </button>

    </div>

  <?php endif; ?>


  <!-- =====================================================
     ERROR MESSAGE
===================================================== -->

  <?php if (!empty($_SESSION['error'])): ?>

    <div class="alert alert-danger alert-dismissible fade show">

      <?= htmlspecialchars($_SESSION['error']); ?>

      <?php unset($_SESSION['error']); ?>

      <button
        type="button"
        class="btn-close"
        onclick="this.closest('.alert').remove()">
      </button>

    </div>

  <?php endif; ?>


  <!-- =====================================================
     CALLBACK TABLE
===================================================== -->

  <div class="table-responsive">

    <table class="table table-hover bg-white shadow">

      <thead class="table-dark">

        <tr>

          <th style="width: 210px; min-width: 210px;">Callback #</th>

          <th style="width: 150px; min-width: 150px;">Lift</th>

          <th>Issue</th>

          <th>Work Report</th>

          <th>Checklist</th>

          <th>Report File</th>

          <th>Status</th>

          <th>Action</th>

        </tr>

      </thead>


      <tbody>


        <?php if ($q->num_rows == 0): ?>

          <tr>

            <td
              colspan="8"
              class="text-center text-muted">

              No callbacks found

            </td>

          </tr>

        <?php endif; ?>


        <?php while ($r = $q->fetch_assoc()): ?>


          <?php

          /* =========================================================
             STATUS BADGE + ACTION
          ========================================================= */

          $cbBadge = '';
          $actionHtml = '';


          /* =========================================================
             1. TECHNICIAN REJECTED
          ========================================================= */

          if ($r['technician_response'] === 'rejected') {

            $cbBadge = "
                <span class='badge bg-danger'>
                    ❌ Rejected by Technician
                </span>
            ";

            $actionHtml = "
                <span class='text-danger fw-bold'>
                    ❌ Technician Rejected
                </span>
            ";


          /* =========================================================
             2. TECHNICIAN ACCEPTED
          ========================================================= */

          } elseif ($r['technician_response'] === 'accepted') {


            /* =====================================================
               CALLBACK WORK COMPLETED
               OWNER CAN APPROVE OR REJECT
            ===================================================== */

            if ($r['status'] === 'closed') {

              $cbBadge = "
                <span class='badge bg-info text-dark'>
                    Pending Owner Approval
                </span>
              ";

              $actionHtml = "

                <a
                    href='approve-callback.php?action=approve&id=" . (int)$r['id'] . "'
                    class='btn btn-sm btn-success mb-1'>

                    ✅ Approve

                </a>

                <a
                    href='approve-callback.php?action=reject&id=" . (int)$r['id'] . "'
                    class='btn btn-sm btn-danger'

                    onclick=\"return confirm('Reject this completed callback and send it back to technician?')\">

                    ❌ Reject

                </a>

              ";


            /* =====================================================
               CALLBACK ALREADY APPROVED
            ===================================================== */

            } elseif ($r['status'] === 'approved') {

              $cbBadge = "
                <span class='badge bg-success'>
                    Approved ✅
                </span>
              ";

              $actionHtml = "
                <span class='text-success fw-bold'>
                    ✅ Approved
                </span>
              ";


            /* =====================================================
               TECHNICIAN ACCEPTED AND WORKING
            ===================================================== */

            } else {

              $cbBadge = "
                <span class='badge bg-primary'>
                    Technician Accepted
                </span>
              ";

              $actionHtml = "
                <span class='text-primary fw-bold'>
                    🔧 Technician Working
                </span>
              ";
            }


          /* =========================================================
             3. TECHNICIAN HAS NOT RESPONDED
          ========================================================= */

          } else {

            $cbBadge = "
                <span class='badge bg-warning text-dark'>
                    Waiting for Technician
                </span>
            ";

            $actionHtml = "
                <span class='text-warning fw-bold'>
                    ⏳ Waiting for Technician
                </span>
            ";
          }


          /* =========================================================
             CHECKLIST
          ========================================================= */

          $checklistHtml = '<span class="text-muted">—</span>';

          if (!empty($r['checklist'])) {

            $cl = json_decode(
                $r['checklist'],
                true
            );

            if (
                is_array($cl)
                && count($cl) > 0
            ) {

              $checklistHtml =
                '<ul class="mb-0 ps-3" style="font-size:12px">';

              foreach ($cl as $item) {

                $checklistHtml .=
                  '<li>' .
                  htmlspecialchars($item) .
                  '</li>';
              }

              $checklistHtml .= '</ul>';
            }
          }

          ?>


          <tr>


            <!-- =====================================================
                 CALLBACK NUMBER
            ===================================================== -->

            <td>

              <strong>

                <?= htmlspecialchars(
                  $r['callback_number']
                ) ?>

              </strong>


              <?php if (!empty($r['technician_name'])): ?>

                <br>

                <small class="text-muted">

                  Technician:
                  <?= htmlspecialchars(
                    $r['technician_name']
                  ) ?>

                </small>

              <?php endif; ?>


              <!-- =====================================================
                   SUBMITTED TIME - UTC TO IST
              ===================================================== -->

              <?php if (!empty($r['work_closed_at'])): ?>

                <br>

                <small class="text-muted">

                  Submitted:

                  <?php

                  try {

                    $submittedDate = new DateTime(
                      $r['work_closed_at'],
                      new DateTimeZone('UTC')
                    );

                    $submittedDate->setTimezone(
                      new DateTimeZone('Asia/Kolkata')
                    );

                    echo htmlspecialchars(
                      $submittedDate->format('d M Y')
                    );

                    echo '<br>';

                    echo htmlspecialchars(
                      $submittedDate->format('h:i A')
                    );

                  } catch (Exception $e) {

                    echo '—';
                  }

                  ?>

                </small>


              <!-- =====================================================
                   STARTED TIME - UTC TO IST
              ===================================================== -->

              <?php elseif (!empty($r['work_started_at'])): ?>

                <br>

                <small class="text-muted">

                  Started:

                  <?php

                  try {

                    $startedDate = new DateTime(
                      $r['work_started_at'],
                      new DateTimeZone('UTC')
                    );

                    $startedDate->setTimezone(
                      new DateTimeZone('Asia/Kolkata')
                    );

                    echo htmlspecialchars(
                      $startedDate->format('d M Y')
                    );

                    echo '<br>';

                    echo htmlspecialchars(
                      $startedDate->format('h:i A')
                    );

                  } catch (Exception $e) {

                    echo '—';
                  }

                  ?>

                </small>

              <?php endif; ?>


              <!-- =====================================================
                   TECHNICIAN RESPONDED TIME - UTC TO IST
              ===================================================== -->

              <?php if (!empty($r['technician_responded_at'])): ?>

                <br>

                <small class="text-muted">

                  Responded:

                  <?php

                  try {

                    $respondedDate = new DateTime(
                      $r['technician_responded_at'],
                      new DateTimeZone('UTC')
                    );

                    $respondedDate->setTimezone(
                      new DateTimeZone('Asia/Kolkata')
                    );

                    echo htmlspecialchars(
                      $respondedDate->format('d M Y')
                    );

                    echo '<br>';

                    echo htmlspecialchars(
                      $respondedDate->format('h:i A')
                    );

                  } catch (Exception $e) {

                    echo '—';
                  }

                  ?>

                </small>

              <?php endif; ?>

            </td>


            <!-- =====================================================
                 LIFT
            ===================================================== -->

            <td>

              <?= htmlspecialchars(
                $r['asset_name'] ?? 'General'
              ) ?>

            </td>


            <!-- =====================================================
                 ISSUE
            ===================================================== -->

            <td>

              <?= htmlspecialchars(
                $r['issue'] ?? '—'
              ) ?>

            </td>


            <!-- =====================================================
                 WORK REPORT
            ===================================================== -->

            <td style="max-width:200px">

              <?php if (!empty($r['work_report'])): ?>

                <?= nl2br(
                  htmlspecialchars(
                    $r['work_report']
                  )
                ) ?>

              <?php else: ?>

                <span class="text-muted">
                  Not submitted
                </span>

              <?php endif; ?>

            </td>


            <!-- =====================================================
                 CHECKLIST
            ===================================================== -->

            <td style="max-width:200px">

              <?= $checklistHtml ?>

            </td>


            <!-- =====================================================
                 REPORT FILE
            ===================================================== -->

            <td>

              <?php if (!empty($r['work_report_file'])):

                $filePath =
                  __DIR__ .
                  '/../uploads/callback_reports/' .
                  $r['work_report_file'];

                if (file_exists($filePath)): ?>

                  <a
                    href="../uploads/callback_reports/<?= htmlspecialchars($r['work_report_file']) ?>"
                    target="_blank"
                    class="btn btn-sm btn-outline-primary">

                    View File

                  </a>


                <?php else: ?>

                  <span class="badge bg-warning text-dark">

                    File Not Found

                  </span>


                <?php endif;

              else: ?>

                <span class="text-muted">

                  No file

                </span>

              <?php endif; ?>

            </td>


            <!-- =====================================================
                 STATUS
            ===================================================== -->

            <td>

              <?= $cbBadge ?>


              <?php if (
                $r['technician_response'] === 'rejected'
                && !empty($r['technician_reject_reason'])
              ): ?>

                <br>

                <small class="text-danger">

                  <strong>Reason:</strong>

                  <?= htmlspecialchars(
                    $r['technician_reject_reason']
                  ) ?>

                </small>

              <?php endif; ?>

            </td>


            <!-- =====================================================
                 ACTION
            ===================================================== -->

            <td>

              <?= $actionHtml ?>

            </td>


          </tr>


        <?php endwhile; ?>


      </tbody>

    </table>

  </div>

</div>


<?php include('../includes/footer.php'); ?>