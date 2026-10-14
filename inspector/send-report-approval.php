<?php
/*
|--------------------------------------------------------------------------
| NLETA INSPECTOR — SEND REPORT FOR ADMIN APPROVAL
|--------------------------------------------------------------------------
| Compatibility file for existing URL:
|
| send-report-approval.php?request_id=3
|
| Actual Inspector processing file:
|
| send_report_approval.php
|--------------------------------------------------------------------------
*/

$requestId = (int)(
    $_GET['request_id']
    ?? $_POST['request_id']
    ?? 0
);

if ($requestId > 0) {

    header(
        'Location: send_report_approval.php?request_id='
        . $requestId
    );

    exit;
}

header(
    'Location: report_requests.php'
);

exit;
?>