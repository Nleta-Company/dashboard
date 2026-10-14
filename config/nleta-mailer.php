<?php

/* =========================================================
   NLETA GENERAL MAILER

   PURPOSE:
   - Service request notification to Admin
   - Service request accepted notification to Owner
   - Service request rejected notification to Owner
   - Future Offer / Payment notifications

   OTP emails remain in:
   config/otp-mailer.php

   TIMEZONE:
   Asia/Kolkata (IST)
========================================================= */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/* =========================================================
   TIMEZONE
========================================================= */

date_default_timezone_set('Asia/Kolkata');


/* =========================================================
   PHPMailer
========================================================= */

require_once __DIR__ . '/../vendor/autoload.php';


/* =========================================================
   SMTP CONFIGURATION
========================================================= */

const NLETA_SMTP_HOST =
'smtp.gmail.com';

const NLETA_SMTP_PORT =
587;


/*
 * Gmail account used to SEND emails.
 *
 * IMPORTANT:
 * Put your NEW Gmail App Password here.
 *
 * Do NOT use your normal Gmail password.
 */

const NLETA_SMTP_USERNAME =
'info@nleta.org.in';

const NLETA_SMTP_PASSWORD =
'ngvaulmjmkddvbma';


/* =========================================================
   SENDER
========================================================= */

const NLETA_FROM_EMAIL =
'info@nleta.org.in';

const NLETA_FROM_NAME =
'NLETA Maintenance System';


/* =========================================================
   ADMIN EMAIL
========================================================= */

const NLETA_ADMIN_EMAIL =
'info@nleta.org.in';

const NLETA_ADMIN_NAME =
'NLETA Admin';


/* =========================================================
   ADMIN LOGIN URL
========================================================= */

const NLETA_ADMIN_LOGIN_URL =
'http://localhost/dashboard_original/dashboard_original/login.php';


/* =========================================================
   OWNER DASHBOARD URL
========================================================= */

const NLETA_OWNER_LOGIN_URL =
'http://localhost/dashboard_original/dashboard_original/login.php';


/* =========================================================
   COMMON SMTP SETUP
========================================================= */

function nletaCreateMailer(): PHPMailer
{
    $mail =
        new PHPMailer(true);

    $mail->isSMTP();

    $mail->Host =
        NLETA_SMTP_HOST;

    $mail->SMTPAuth =
        true;

    $mail->Username =
        NLETA_SMTP_USERNAME;

    $mail->Password =
        NLETA_SMTP_PASSWORD;

    $mail->SMTPSecure =
        PHPMailer::ENCRYPTION_STARTTLS;

    $mail->Port =
        NLETA_SMTP_PORT;

    $mail->Timeout =
        30;

    $mail->CharSet =
        'UTF-8';

    $mail->setFrom(
        NLETA_FROM_EMAIL,
        NLETA_FROM_NAME
    );

    return $mail;
}


/* =========================================================
   HTML ESCAPE HELPER
========================================================= */

function nletaSafe(
    mixed $value
): string {

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/* =========================================================
   SERVICE DISPLAY HELPERS
   ---------------------------------------------------------
   Supports both:
   - legacy single service string
   - new multi-service array

   This keeps existing callers working while allowing the
   mailer to display every selected service.
========================================================= */

function nletaNormalizeServices(
    string|array $services
): array {

    if (is_string($services)) {
        $services = [$services];
    }

    $result = [];

    foreach ($services as $service) {
        $service = trim((string)$service);

        if ($service !== '' && !in_array($service, $result, true)) {
            $result[] = $service;
        }
    }

    return $result;
}

function nletaServicesHtml(
    string|array $services
): string {

    $services = nletaNormalizeServices($services);

    if (!$services) {
        return '—';
    }

    if (count($services) === 1) {
        return nletaSafe($services[0]);
    }

    $html = '<div style="line-height:22px;">';

    foreach ($services as $index => $service) {
        $html .=
            '<div style="margin-bottom:4px;">'
            . '<strong>' . ($index + 1) . '.</strong> '
            . nletaSafe($service)
            . '</div>';
    }

    $html .= '</div>';

    return $html;
}

function nletaServicesText(
    string|array $services
): string {

    $services = nletaNormalizeServices($services);

    if (!$services) {
        return '—';
    }

    return implode(', ', $services);
}


/* =========================================================
   1. NEW SERVICE REQUEST → ADMIN
========================================================= */

function sendNletaServiceRequestAdminEmail(
    int $requestId,
    string $requestNo,
    string $ownerName,
    string $ownerEmail,
    string|array $serviceName,
    string $submittedAt,
    int $assetCount
): bool {

    try {

        $mail =
            nletaCreateMailer();


        $mail->addAddress(
            NLETA_ADMIN_EMAIL,
            NLETA_ADMIN_NAME
        );


        /* =================================================
           SAFE VALUES
        ================================================= */

        $safeRequestNo =
            nletaSafe(
                $requestNo
            );

        $safeOwnerName =
            nletaSafe(
                $ownerName
            );

        $safeOwnerEmail =
            nletaSafe(
                $ownerEmail
            );

        $safeServiceName =
            nletaServicesHtml(
                $serviceName
            );

        $safeSubmittedAt =
            nletaSafe(
                $submittedAt
            );

        $safeAssetCount =
            (int)$assetCount;


        /* =================================================
           ADMIN LOGIN URL
        ================================================= */

        $reviewUrl =
            NLETA_ADMIN_LOGIN_URL;


        $safeReviewUrl =
            nletaSafe(
                $reviewUrl
            );


        /* =================================================
           SUBJECT
        ================================================= */

        $mail->Subject =
            'NLETA - New Service Request - '
            .
            $requestNo;


        $mail->isHTML(true);


        /* =================================================
           HTML BODY
        ================================================= */

        $mail->Body = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
NLETA Service Request
</title>

</head>


<body
style="
    margin:0;
    padding:0;
    background:#f4f6f9;
    font-family:Arial,Helvetica,sans-serif;
"
>


<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
    background:#f4f6f9;
    padding:30px 10px;
"
>

<tr>

<td align="center">


<table
width="600"
cellpadding="0"
cellspacing="0"
border="0"
style="
    max-width:600px;
    width:100%;
    background:#ffffff;
    border:1px solid #e1e5ea;
    border-radius:10px;
    overflow:hidden;
"
>


<!-- HEADER -->

<tr>

<td
style="
    background:#1f2933;
    color:#ffffff;
    padding:25px;
    text-align:center;
"
>

<div
style="
    font-size:25px;
    font-weight:bold;
"
>
NLETA
</div>


<div
style="
    margin-top:7px;
    font-size:14px;
"
>
National Lift Escalator Testing Agency
</div>


<div
style="
    margin-top:5px;
    font-size:12px;
    color:#d1d5db;
"
>
Maintenance Management System
</div>

</td>

</tr>


<!-- CONTENT -->

<tr>

<td
style="
    padding:30px;
    color:#222222;
"
>

<h2
style="
    margin:0 0 20px;
    color:#1f2933;
"
>
New Service Request Received
</h2>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

A new Service Request has been submitted by an Owner
and is waiting for Admin review.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
style="
    border-collapse:collapse;
    margin-top:20px;
"
>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Request No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeRequestNo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Owner
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeOwnerName . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Owner Email
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeOwnerEmail . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeServiceName . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Selected Assets
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeAssetCount . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Submitted
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeSubmittedAt . ' IST
</td>

</tr>


</table>


<!-- BUTTON -->

<div
style="
    text-align:center;
    margin-top:30px;
"
>

<a
href="' . $safeReviewUrl . '"
style="
    display:inline-block;
    background:#0d6efd;
    color:#ffffff;
    text-decoration:none;
    padding:13px 25px;
    border-radius:6px;
    font-weight:bold;
    font-size:14px;
"
>
Review Service Request
</a>

</div>


<p
style="
    margin-top:25px;
    font-size:13px;
    line-height:21px;
    color:#6b7280;
"
>

Clicking the button will open the Admin Login page.
After login, you can review and Accept or Reject the request.

</p>


</td>

</tr>


<!-- FOOTER -->

<tr>

<td
style="
    background:#f4f6f9;
    padding:18px;
    text-align:center;
    font-size:12px;
    color:#777777;
"
>

This is an automated email from the
NLETA Maintenance Management System.

<br>

© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.

</td>

</tr>


</table>

</td>

</tr>

</table>

</body>

</html>
';


        /* =================================================
           PLAIN TEXT
        ================================================= */

        $mail->AltBody =
            "NLETA Maintenance Management System\n\n"
            .
            "New Service Request Received\n\n"
            .
            "Request No: "
            . $requestNo
            . "\n"
            .
            "Owner: "
            . $ownerName
            . "\n"
            .
            "Owner Email: "
            . $ownerEmail
            . "\n"
            .
            "Service: "
            . nletaServicesText($serviceName)
            . "\n"
            .
            "Selected Assets: "
            . $assetCount
            . "\n"
            .
            "Submitted: "
            . $submittedAt
            . " IST\n\n"
            .
            "Admin Login:\n"
            .
            $reviewUrl;


        $mail->send();

        return true;
    } catch (
        Exception $e
    ) {

        error_log(
            'NLETA Admin Service Request Email Error: '
                .
                $e->getMessage()
        );

        return false;
    }
}


/* =========================================================
   2. ADMIN ACCEPTS → OWNER
========================================================= */

function sendNletaServiceRequestAcceptedEmail(
    string $ownerEmail,
    string $ownerName,
    string $requestNo,
    string|array $serviceName,
    int $assetCount,
    string $acceptedAt
): bool {

    try {

        $mail =
            nletaCreateMailer();


        $mail->addAddress(
            $ownerEmail,
            $ownerName
        );


        $safeOwnerName =
            nletaSafe(
                $ownerName
            );

        $safeRequestNo =
            nletaSafe(
                $requestNo
            );

        $safeServiceName =
            nletaServicesHtml(
                $serviceName
            );

        $safeAcceptedAt =
            nletaSafe(
                $acceptedAt
            );


        $ownerLoginUrl =
            NLETA_OWNER_LOGIN_URL;


        $safeOwnerLoginUrl =
            nletaSafe(
                $ownerLoginUrl
            );


        $mail->Subject =
            'NLETA - Service Request Accepted - '
            .
            $requestNo;


        $mail->isHTML(true);


        $mail->Body = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
NLETA Service Request Accepted
</title>

</head>


<body
style="
    margin:0;
    padding:0;
    background:#f4f6f9;
    font-family:Arial,Helvetica,sans-serif;
"
>


<table
width="100%"
cellpadding="0"
cellspacing="0"
style="
    background:#f4f6f9;
    padding:30px 10px;
"
>

<tr>

<td align="center">


<table
width="600"
cellpadding="0"
cellspacing="0"
style="
    max-width:600px;
    width:100%;
    background:#ffffff;
    border:1px solid #e1e5ea;
    border-radius:10px;
    overflow:hidden;
"
>


<tr>

<td
style="
    background:#198754;
    color:#ffffff;
    padding:25px;
    text-align:center;
"
>

<div
style="
    font-size:25px;
    font-weight:bold;
"
>
NLETA
</div>

<div
style="
    margin-top:7px;
    font-size:14px;
"
>
Maintenance Management System
</div>

</td>

</tr>


<tr>

<td
style="
    padding:30px;
    color:#222222;
"
>


<h2
style="
    margin:0 0 20px;
    color:#198754;
"
>
Service Request Accepted
</h2>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

Dear <strong>
' . $safeOwnerName . '
</strong>,

</p>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

Your Service Request has been
<strong>accepted by NLETA Admin</strong>.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
style="
    border-collapse:collapse;
    margin-top:20px;
"
>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Request No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeRequestNo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeServiceName . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Selected Assets
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . (int)$assetCount . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Status
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#856404;
    font-weight:bold;
"
>
Offer Accepted — PSGA Generation Soon
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Accepted On
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeAcceptedAt . ' IST
</td>

</tr>


</table>


<div
style="
    margin-top:25px;
    padding:18px;
    background:#fff8e1;
    border:1px solid #ffe69c;
    border-radius:7px;
    line-height:23px;
"
>

<strong>
Offer Accepted — PSGA Generation Soon
</strong>

<br><br>

Your Service Request has been accepted.

The Service Offer and PSGA are now pending generation.

You will receive another notification when the Offer is ready.

</div>


<div
style="
    text-align:center;
    margin-top:28px;
"
>

<a
href="' . $safeOwnerLoginUrl . '"
style="
    display:inline-block;
    background:#0d6efd;
    color:#ffffff;
    text-decoration:none;
    padding:13px 25px;
    border-radius:6px;
    font-weight:bold;
"
>
Login to Owner Dashboard
</a>

</div>


</td>

</tr>


<tr>

<td
style="
    background:#f4f6f9;
    padding:18px;
    text-align:center;
    font-size:12px;
    color:#777777;
"
>

This is an automated email from the
NLETA Maintenance Management System.

</td>

</tr>


</table>

</td>

</tr>

</table>

</body>

</html>
';


        $mail->AltBody =
            "Dear "
            . $ownerName
            . ",\n\n"
            .
            "Your Service Request "
            . $requestNo
            .
            " has been accepted by NLETA Admin.\n\n"
            .
            "Service: "
            . nletaServicesText($serviceName)
            . "\n"
            .
            "Selected Assets: "
            . $assetCount
            . "\n"
            .
            "Status: Offer Accepted — PSGA Generation Soon\n"
            .
            "Accepted On: "
            . $acceptedAt
            . " IST\n\n"
            .
            "The Service Offer and PSGA are now pending generation.\n\n"
            .
            "Owner Dashboard:\n"
            .
            $ownerLoginUrl;


        $mail->send();

        return true;
    } catch (
        Exception $e
    ) {

        error_log(
            'NLETA Owner Acceptance Email Error: '
                .
                $e->getMessage()
        );

        return false;
    }
}


/* =========================================================
   3. ADMIN REJECTS → OWNER
========================================================= */

function sendNletaServiceRequestRejectedEmail(
    string $ownerEmail,
    string $ownerName,
    string $requestNo,
    string|array $serviceName,
    int $assetCount,
    string $rejectedAt,
    string $rejectionReason = ''
): bool {

    try {

        $mail =
            nletaCreateMailer();


        $mail->addAddress(
            $ownerEmail,
            $ownerName
        );


        $safeOwnerName =
            nletaSafe(
                $ownerName
            );

        $safeRequestNo =
            nletaSafe(
                $requestNo
            );

        $safeServiceName =
            nletaServicesHtml(
                $serviceName
            );

        $safeRejectedAt =
            nletaSafe(
                $rejectedAt
            );

        $safeReason =
            nletaSafe(
                trim(
                    $rejectionReason
                )
            );


        $ownerLoginUrl =
            NLETA_OWNER_LOGIN_URL;


        $safeOwnerLoginUrl =
            nletaSafe(
                $ownerLoginUrl
            );


        $mail->Subject =
            'NLETA - Service Request Rejected - '
            .
            $requestNo;


        $mail->isHTML(true);


        $reasonHtml =
            'No rejection reason was provided.';

        if (
            trim(
                $rejectionReason
            ) !== ''
        ) {

            $reasonHtml =
                $safeReason;
        }


        $mail->Body = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
NLETA Service Request Rejected
</title>

</head>


<body
style="
    margin:0;
    padding:0;
    background:#f4f6f9;
    font-family:Arial,Helvetica,sans-serif;
"
>


<table
width="100%"
cellpadding="0"
cellspacing="0"
style="
    background:#f4f6f9;
    padding:30px 10px;
"
>

<tr>

<td align="center">


<table
width="600"
cellpadding="0"
cellspacing="0"
style="
    max-width:600px;
    width:100%;
    background:#ffffff;
    border:1px solid #e1e5ea;
    border-radius:10px;
    overflow:hidden;
"
>


<tr>

<td
style="
    background:#dc3545;
    color:#ffffff;
    padding:25px;
    text-align:center;
"
>

<div
style="
    font-size:25px;
    font-weight:bold;
"
>
NLETA
</div>

<div
style="
    margin-top:7px;
    font-size:14px;
"
>
Maintenance Management System
</div>

</td>

</tr>


<tr>

<td
style="
    padding:30px;
    color:#222222;
"
>


<h2
style="
    margin:0 0 20px;
    color:#dc3545;
"
>
Service Request Rejected
</h2>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

Dear <strong>
' . $safeOwnerName . '
</strong>,

</p>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

Your Service Request has been
<strong>rejected by NLETA Admin</strong>.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
style="
    border-collapse:collapse;
    margin-top:20px;
"
>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Request No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeRequestNo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeServiceName . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Selected Assets
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . (int)$assetCount . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Status
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#dc3545;
    font-weight:bold;
"
>
Rejected
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Rejected On
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeRejectedAt . ' IST
</td>

</tr>


</table>


<div
style="
    margin-top:25px;
    padding:18px;
    background:#fff5f5;
    border:1px solid #f5c2c7;
    border-radius:7px;
    line-height:23px;
"
>

<strong>
Rejection Reason
</strong>

<br><br>

' . $reasonHtml . '

</div>


<div
style="
    text-align:center;
    margin-top:28px;
"
>

<a
href="' . $safeOwnerLoginUrl . '"
style="
    display:inline-block;
    background:#0d6efd;
    color:#ffffff;
    text-decoration:none;
    padding:13px 25px;
    border-radius:6px;
    font-weight:bold;
"
>
Login to Owner Dashboard
</a>

</div>


</td>

</tr>


<tr>

<td
style="
    background:#f4f6f9;
    padding:18px;
    text-align:center;
    font-size:12px;
    color:#777777;
"
>

This is an automated email from the
NLETA Maintenance Management System.

</td>

</tr>


</table>

</td>

</tr>

</table>

</body>

</html>
';


        $mail->AltBody =
            "Dear "
            . $ownerName
            . ",\n\n"
            .
            "Your Service Request "
            . $requestNo
            .
            " has been rejected by NLETA Admin.\n\n"
            .
            "Service: "
            . nletaServicesText($serviceName)
            . "\n"
            .
            "Selected Assets: "
            . $assetCount
            . "\n"
            .
            "Status: Rejected\n"
            .
            "Rejected On: "
            . $rejectedAt
            . " IST\n\n"
            .
            "Rejection Reason:\n"
            .
            (
                trim($rejectionReason)
                !== ''
                ? $rejectionReason
                : 'No rejection reason was provided.'
            )
            .
            "\n\n"
            .
            "Owner Dashboard:\n"
            .
            $ownerLoginUrl;


        $mail->send();

        return true;
    } catch (
        Exception $e
    ) {

        error_log(
            'NLETA Owner Rejection Email Error: '
                .
                $e->getMessage()
        );

        return false;
    }
}

/* =========================================================
   4. OFFER GENERATED → OWNER
========================================================= */

function sendNletaOfferGeneratedEmail(
    string $ownerEmail,
    string $ownerName,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string|array $serviceName,
    int $assetCount,
    float $grandTotal,
    string $offerDate,
    string $validityDate
): bool {

    try {

        if (
            trim($ownerEmail) === ''
            ||
            !filter_var(
                $ownerEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            return false;
        }

        $mail = nletaCreateMailer();

        $mail->addAddress(
            $ownerEmail,
            $ownerName ?: 'Owner'
        );

        $mail->Subject =
            'NLETA - Offer Generated - ' .
            $offerNo;

        $mail->isHTML(true);

        $ownerLoginUrl =
            NLETA_OWNER_LOGIN_URL;

        $mail->Body = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
NLETA Offer Generated
</title>

</head>

<body
style="
    margin:0;
    padding:0;
    background:#f4f6f9;
    font-family:Arial,Helvetica,sans-serif;
"
>

<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
    background:#f4f6f9;
    padding:30px 10px;
"
>

<tr>

<td align="center">

<table
width="600"
cellpadding="0"
cellspacing="0"
border="0"
style="
    max-width:600px;
    width:100%;
    background:#ffffff;
    border:1px solid #e1e5ea;
    border-radius:10px;
    overflow:hidden;
"
>

<tr>

<td
style="
    background:#198754;
    color:#ffffff;
    padding:25px;
    text-align:center;
"
>

<div
style="
    font-size:25px;
    font-weight:bold;
"
>
NLETA
</div>

<div
style="
    margin-top:7px;
    font-size:14px;
"
>
National Lift Escalator Testing Agency
</div>

<div
style="
    margin-top:5px;
    font-size:12px;
    color:#d1d5db;
"
>
Maintenance Management System
</div>

</td>

</tr>

<tr>

<td
style="
    padding:30px;
    color:#222222;
"
>

<h2
style="
    margin:0 0 18px;
    color:#198754;
"
>
Offer Generated Successfully
</h2>

<p
style="
    font-size:15px;
    line-height:24px;
"
>

Dear <strong>
' .
            nletaSafe(
                $ownerName ?: 'Owner'
            )
            .
            '</strong>,

</p>

<p
style="
    font-size:14px;
    line-height:23px;
"
>

Your NLETA Financial &amp; Service Offer
(FSO-01) has been generated successfully
and is ready for review.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
    border-collapse:collapse;
    margin-top:20px;
"
>

<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service Request
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaSafe($requestNo)
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Offer No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaSafe($offerNo)
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
PSGA No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaSafe($psgaNo)
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaServicesHtml($serviceName)
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Selected Assets
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            (int)$assetCount
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Grand Total
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#198754;
    font-weight:bold;
"
>
₹' .
            number_format(
                $grandTotal,
                2,
                '.',
                ','
            )
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Offer Date
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaSafe($offerDate)
            .
            ' IST
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Valid Until
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' .
            nletaSafe($validityDate)
            .
            '</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Status
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#198754;
    font-weight:bold;
"
>
Offer Generated
</td>

</tr>

</table>


<div
style="
    margin-top:25px;
    padding:18px;
    background:#f0fdf4;
    border:1px solid #bbf7d0;
    border-radius:7px;
    line-height:23px;
"
>

<strong>
Your Offer is Ready
</strong>

<br><br>

The NLETA Financial &amp; Service Offer
(FSO-01) has been generated and is available
for review in your Owner Dashboard.

</div>


<div
style="
    text-align:center;
    margin-top:28px;
"
>

<a
href="' .
            htmlspecialchars(
                $ownerLoginUrl,
                ENT_QUOTES,
                'UTF-8'
            )
            .
            '"
style="
    display:inline-block;
    background:#0d6efd;
    color:#ffffff;
    text-decoration:none;
    padding:13px 25px;
    border-radius:6px;
    font-weight:bold;
    font-size:14px;
"
>
Login to Owner Dashboard
</a>

</div>


<p
style="
    margin-top:25px;
    font-size:13px;
    line-height:21px;
    color:#6b7280;
"
>

Please login to your Owner Dashboard
to review the complete FSO-01 Offer.

</p>

</td>

</tr>


<tr>

<td
style="
    background:#f4f6f9;
    padding:18px;
    text-align:center;
    font-size:12px;
    color:#777777;
    line-height:18px;
"
>

This is an automated email from the
NLETA Maintenance Management System.

<br>

© ' .
            (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y')
            .
            ' NLETA. All Rights Reserved.

</td>

</tr>

</table>

</td>

</tr>

</table>

</body>

</html>
';


        $mail->AltBody =
            "Dear "
            .
            ($ownerName ?: 'Owner')
            .
            ",\n\n"
            .
            "Your NLETA Financial & Service Offer "
            .
            "(FSO-01) has been generated successfully.\n\n"
            .
            "Service Request: "
            .
            $requestNo
            .
            "\n"
            .
            "Offer No.: "
            .
            $offerNo
            .
            "\n"
            .
            "PSGA No.: "
            .
            $psgaNo
            .
            "\n"
            .
            "Service: "
            .
            nletaServicesText($serviceName)
            .
            "\n"
            .
            "Selected Assets: "
            .
            $assetCount
            .
            "\n"
            .
            "Grand Total: ₹"
            .
            number_format(
                $grandTotal,
                2
            )
            .
            "\n"
            .
            "Offer Date: "
            .
            $offerDate
            .
            " IST\n"
            .
            "Valid Until: "
            .
            $validityDate
            .
            "\n"
            .
            "Status: Offer Generated\n\n"
            .
            "Owner Dashboard:\n"
            .
            $ownerLoginUrl;


        $mail->send();

        return true;
    } catch (
        Throwable $e
    ) {

        error_log(
            'NLETA Offer Generated Email Error: '
                .
                $e->getMessage()
        );

        return false;
    }
}

/* =========================================================
   5. OFFER SENT BY ADMIN → OWNER
========================================================= */

function sendNletaOfferSentEmail(
    string $ownerEmail,
    string $ownerName,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string|array $serviceName,
    int $assetCount,
    float $grandTotal,
    string $offerDate,
    string $validityDate
): bool {

    try {

        $ownerEmail =
            trim($ownerEmail);

        $ownerName =
            trim($ownerName);

        if (
            $ownerEmail === ''
            ||
            !filter_var(
                $ownerEmail,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            error_log(
                'NLETA Offer Sent Email Error: Invalid Owner email address.'
            );

            return false;
        }


        $mail =
            nletaCreateMailer();


        $mail->addAddress(
            $ownerEmail,
            $ownerName ?: 'Owner'
        );


        $mail->Subject =
            'NLETA - FSO-01 Offer Sent - '
            .
            $offerNo;


        $mail->isHTML(true);


        $ownerLoginUrl =
            NLETA_OWNER_LOGIN_URL;


        $safeOwnerName =
            nletaSafe(
                $ownerName ?: 'Owner'
            );

        $safeRequestNo =
            nletaSafe(
                $requestNo
            );

        $safeOfferNo =
            nletaSafe(
                $offerNo
            );

        $safePSGANo =
            nletaSafe(
                $psgaNo
            );

        $safeServiceName =
            nletaSafe(
                $serviceName
            );

        $safeOfferDate =
            nletaSafe(
                $offerDate
            );

        $safeValidityDate =
            nletaSafe(
                $validityDate
            );

        $safeOwnerLoginUrl =
            nletaSafe(
                $ownerLoginUrl
            );


        $formattedTotal =
            number_format(
                $grandTotal,
                2,
                '.',
                ','
            );


        $mail->Body = '

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
NLETA FSO-01 Offer
</title>

</head>


<body
style="
    margin:0;
    padding:0;
    background:#f4f6f9;
    font-family:Arial,Helvetica,sans-serif;
"
>

<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
    background:#f4f6f9;
    padding:30px 10px;
"
>

<tr>

<td align="center">


<table
width="600"
cellpadding="0"
cellspacing="0"
border="0"
style="
    max-width:600px;
    width:100%;
    background:#ffffff;
    border:1px solid #e1e5ea;
    border-radius:10px;
    overflow:hidden;
"
>


<!-- HEADER -->

<tr>

<td
style="
    background:#198754;
    color:#ffffff;
    padding:25px;
    text-align:center;
"
>

<div
style="
    font-size:25px;
    font-weight:bold;
"
>
NLETA
</div>


<div
style="
    margin-top:7px;
    font-size:14px;
"
>
National Lift Escalator Testing Agency
</div>


<div
style="
    margin-top:5px;
    font-size:12px;
    color:#d1d5db;
"
>
Maintenance Management System
</div>

</td>

</tr>


<!-- CONTENT -->

<tr>

<td
style="
    padding:30px;
    color:#222222;
"
>


<h2
style="
    margin:0 0 18px;
    color:#198754;
"
>
NLETA Financial &amp; Service Offer Sent
</h2>


<p
style="
    font-size:15px;
    line-height:24px;
"
>

Dear <strong>
' . $safeOwnerName . '
</strong>,

</p>


<p
style="
    font-size:14px;
    line-height:23px;
"
>

NLETA Admin has reviewed your
Financial &amp; Service Offer (FSO-01)
and has sent it to you for review.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
    border-collapse:collapse;
    margin-top:20px;
"
>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service Request
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeRequestNo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
FSO-01 Offer No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
' . $safeOfferNo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
PSGA No.
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safePSGANo . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Service
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeServiceName . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Selected Assets
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . (int)$assetCount . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Grand Total
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#198754;
    font-weight:bold;
"
>
₹' . $formattedTotal . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Offer Date
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeOfferDate . ' IST
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Valid Until
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
"
>
' . $safeValidityDate . '
</td>

</tr>


<tr>

<td
style="
    padding:10px;
    background:#f8f9fa;
    border:1px solid #e5e7eb;
    font-weight:bold;
"
>
Status
</td>

<td
style="
    padding:10px;
    border:1px solid #e5e7eb;
    color:#198754;
    font-weight:bold;
"
>
Sent to Owner
</td>

</tr>


</table>


<div
style="
    margin-top:25px;
    padding:18px;
    background:#f0fdf4;
    border:1px solid #bbf7d0;
    border-radius:7px;
    line-height:23px;
"
>

<strong>
Your Offer is now available in the Owner Dashboard.
</strong>

<br><br>

Please login to review the complete FSO-01.
The Offer has been made available to your
Owner Dashboard by NLETA Admin.

</div>


<div
style="
    text-align:center;
    margin-top:28px;
"
>

<a
href="' . $safeOwnerLoginUrl . '"
style="
    display:inline-block;
    background:#0d6efd;
    color:#ffffff;
    text-decoration:none;
    padding:13px 25px;
    border-radius:6px;
    font-weight:bold;
    font-size:14px;
"
>
Open Owner Dashboard
</a>

</div>


<p
style="
    margin-top:25px;
    font-size:13px;
    line-height:21px;
    color:#6b7280;
"
>

If you have any questions regarding this Offer,
please contact NLETA.

</p>


</td>

</tr>


<!-- FOOTER -->

<tr>

<td
style="
    background:#f4f6f9;
    padding:18px;
    text-align:center;
    font-size:12px;
    color:#777777;
    line-height:18px;
"
>

WEB: www.nleta.org.in

<br>

EMAIL: info@nleta.org.in

<br><br>

This is an automated email from the
NLETA Maintenance Management System.

<br>

© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.

</td>

</tr>


</table>

</td>

</tr>

</table>

</body>

</html>
';


        $mail->AltBody =
            "Dear "
            .
            ($ownerName ?: 'Owner')
            .
            ",\n\n"
            .
            "NLETA Admin has reviewed and sent your "
            .
            "Financial & Service Offer (FSO-01).\n\n"
            .
            "Service Request: "
            .
            $requestNo
            .
            "\n"
            .
            "FSO-01 Offer No.: "
            .
            $offerNo
            .
            "\n"
            .
            "PSGA No.: "
            .
            $psgaNo
            .
            "\n"
            .
            "Service: "
            .
            nletaServicesText($serviceName)
            .
            "\n"
            .
            "Selected Assets: "
            .
            $assetCount
            .
            "\n"
            .
            "Grand Total: ₹"
            .
            $formattedTotal
            .
            "\n"
            .
            "Offer Date: "
            .
            $offerDate
            .
            " IST\n"
            .
            "Valid Until: "
            .
            $validityDate
            .
            "\n"
            .
            "Status: Sent to Owner\n\n"
            .
            "Owner Dashboard:\n"
            .
            $ownerLoginUrl;


        $mail->send();

        return true;

    } catch (
        Throwable $e
    ) {

        error_log(
            'NLETA Offer Sent Email Error: '
            .
            $e->getMessage()
        );

        return false;
    }
}


/* =========================================================
   6. OWNER PAYMENT SUBMITTED → ADMIN
   ---------------------------------------------------------
   Sends an email every time an Owner successfully submits
   a payment transaction for Admin verification.

   Covers:
   - Advance
   - Service Activation
   - Final
   - Resubmission after rejection

   Payment proof is attached when the file exists.
========================================================= */

function sendNletaPaymentSubmittedAdminEmail(
    int $transactionId,
    int $scheduleId,
    string $ownerName,
    string $ownerEmail,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string $paymentStage,
    float $amount,
    string $paymentMethod,
    string $transactionReference,
    string $paymentDate,
    string $submittedAt,
    string $proofFullPath = ''
): bool {

    try {

        if (!class_exists('\PHPMailer\PHPMailer\PHPMailer')) {
            error_log(
                'NLETA Payment Email Error: PHPMailer class not available.'
            );
            return false;
        }

        $mail = nletaCreateMailer();

        $mail->addAddress(
            NLETA_ADMIN_EMAIL,
            NLETA_ADMIN_NAME
        );

        /*
         * Attach proof if available.
         * If attachment fails, email still goes without it.
         */
        if (
            $proofFullPath !== ''
            &&
            is_file($proofFullPath)
            &&
            is_readable($proofFullPath)
        ) {
            try {
                $mail->addAttachment(
                    $proofFullPath,
                    basename($proofFullPath)
                );
            } catch (Throwable $attachmentError) {
                error_log(
                    'NLETA Payment Proof Attachment Error: '
                    . $attachmentError->getMessage()
                );
            }
        }

        $safeOwnerName = nletaSafe(
            $ownerName !== '' ? $ownerName : 'Owner'
        );

        $safeOwnerEmail = nletaSafe($ownerEmail);

        $safeRequestNo = nletaSafe(
            $requestNo !== '' ? $requestNo : '—'
        );

        $safeOfferNo = nletaSafe(
            $offerNo !== '' ? $offerNo : '—'
        );

        $safePsgaNo = nletaSafe(
            $psgaNo !== '' ? $psgaNo : '—'
        );

        $safePaymentStage = nletaSafe(
            $paymentStage !== '' ? $paymentStage : 'Payment'
        );

        $safePaymentMethod = nletaSafe($paymentMethod);
        $safeTransactionReference = nletaSafe($transactionReference);
        $safePaymentDate = nletaSafe($paymentDate);
        $safeSubmittedAt = nletaSafe($submittedAt);

        $formattedAmount = number_format(
            $amount,
            2,
            '.',
            ','
        );

        $adminLoginUrl = nletaSafe(
            NLETA_ADMIN_LOGIN_URL
        );

        $mail->Subject =
            'NLETA - Payment Submitted - '
            . ($paymentStage !== '' ? $paymentStage . ' - ' : '')
            . ($offerNo !== '' ? $offerNo : 'Payment');

        $currentYear = (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y');

        $mail->isHTML(true);

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Payment Submitted</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr>
<td align="center">

<table width="600" cellpadding="0" cellspacing="0" border="0"
style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">

<tr>
<td style="background:#0d6efd;color:#ffffff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#dbeafe;">Maintenance Management System</div>
</td>
</tr>

<tr>
<td style="padding:30px;color:#222222;">

<h2 style="margin:0 0 18px;color:#0d6efd;">
Payment Submitted for Verification
</h2>

<p style="font-size:15px;line-height:24px;">
A payment has been submitted by the Owner and is waiting for Admin verification.
</p>

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="border-collapse:collapse;margin-top:20px;">

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Transaction ID</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$transactionId}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Payment Stage</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safePaymentStage}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeOwnerName}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner Email</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeOwnerEmail}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service Request</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeRequestNo}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Offer No.</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeOfferNo}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA No.</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safePsgaNo}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Amount</td>
<td style="padding:10px;border:1px solid #e5e7eb;color:#198754;font-weight:bold;">₹{$formattedAmount}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Payment Method</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safePaymentMethod}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">UTR / Transaction Reference</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeTransactionReference}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Payment Date</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safePaymentDate}</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Submitted At</td>
<td style="padding:10px;border:1px solid #e5e7eb;">{$safeSubmittedAt} IST</td>
</tr>

</table>

<div style="margin-top:25px;padding:18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;line-height:23px;">
<strong>Action Required</strong>
<br><br>
Please login to the Admin Dashboard and verify the submitted payment and payment proof.
</div>

<div style="text-align:center;margin-top:28px;">
<a href="{$adminLoginUrl}"
style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;font-size:14px;">
Open Admin Dashboard
</a>
</div>

<p style="margin-top:25px;font-size:13px;line-height:21px;color:#6b7280;">
The payment transaction has been recorded as <strong>Submitted</strong>.
No financial amount is treated as received until Admin verifies it.
</p>

</td>
</tr>

<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;">
This is an automated email from the NLETA Maintenance Management System.
<br>
© {$currentYear} NLETA. All Rights Reserved.
</td>
</tr>

</table>
</td>
</tr>
</table>

</body>
</html>
HTML;

        $mail->AltBody =
            "NLETA Maintenance Management System\n\n"
            . "Payment Submitted for Admin Verification\n\n"
            . "Transaction ID: " . $transactionId . "\n"
            . "Payment Stage: " . $paymentStage . "\n"
            . "Owner: " . ($ownerName ?: 'Owner') . "\n"
            . "Owner Email: " . $ownerEmail . "\n"
            . "Service Request: " . ($requestNo ?: '—') . "\n"
            . "Offer No.: " . ($offerNo ?: '—') . "\n"
            . "PSGA No.: " . ($psgaNo ?: '—') . "\n"
            . "Amount: ₹" . $formattedAmount . "\n"
            . "Payment Method: " . $paymentMethod . "\n"
            . "UTR / Transaction Reference: " . $transactionReference . "\n"
            . "Payment Date: " . $paymentDate . "\n"
            . "Submitted At: " . $submittedAt . " IST\n\n"
            . "Please login to the Admin Dashboard and verify the payment.\n"
            . NLETA_ADMIN_LOGIN_URL;

        $mail->send();

        return true;

    } catch (Throwable $e) {

        error_log(
            'NLETA Payment Submitted Admin Email Error: '
            . $e->getMessage()
        );

        return false;
    }
}

/* =========================================================
   7. PAYMENT VERIFIED → ADMIN
   ---------------------------------------------------------
   Sends an email to Admin every time a submitted payment
   is successfully verified.

   Email failure MUST NOT undo the database verification.
========================================================= */

function sendNletaPaymentVerifiedAdminEmail(
    int $transactionId,
    int $scheduleId,
    string $ownerName,
    string $ownerEmail,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string $paymentStage,
    float $amount,
    string $paymentMethod,
    string $transactionReference,
    string $paymentDate,
    string $verifiedAt,
    float $totalAmount,
    float $receivedAmount,
    float $balanceAmount,
    string $paymentStatus
): bool {

    try {

        $mail = nletaCreateMailer();

        $mail->addAddress(
            NLETA_ADMIN_EMAIL,
            NLETA_ADMIN_NAME
        );

        $mail->Subject =
            'NLETA - Payment Verified - '
            . ($paymentStage ?: 'Payment')
            . ' - '
            . ($offerNo ?: 'Offer')
            . ' - TXN '
            . $transactionId;

        $mail->isHTML(true);

        $safeOwnerName = nletaSafe($ownerName ?: 'Owner');
        $safeOwnerEmail = nletaSafe($ownerEmail ?: '—');
        $safeRequestNo = nletaSafe($requestNo ?: '—');
        $safeOfferNo = nletaSafe($offerNo ?: '—');
        $safePSGANo = nletaSafe($psgaNo ?: '—');
        $safeStage = nletaSafe($paymentStage ?: '—');
        $safeMethod = nletaSafe($paymentMethod ?: '—');
        $safeReference = nletaSafe($transactionReference ?: '—');
        $safePaymentDate = nletaSafe($paymentDate ?: '—');
        $safeVerifiedAt = nletaSafe($verifiedAt ?: '—');
        $safeStatus = nletaSafe($paymentStatus ?: '—');

        $formattedAmount = number_format($amount, 2, '.', ',');
        $formattedTotal = number_format($totalAmount, 2, '.', ',');
        $formattedReceived = number_format($receivedAmount, 2, '.', ',');
        $formattedBalance = number_format($balanceAmount, 2, '.', ',');

        $safeLoginUrl = nletaSafe(NLETA_ADMIN_LOGIN_URL);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Payment Verified</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#198754;color:#ffffff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#d1d5db;">Maintenance Management System</div>
</td>
</tr>
<tr>
<td style="padding:30px;color:#222222;">
<h2 style="margin:0 0 18px;color:#198754;">Payment Verified Successfully</h2>
<p style="font-size:14px;line-height:23px;">A payment transaction has been successfully verified by Admin in the NLETA Maintenance Management System.</p>
<table width="100%" cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;font-size:14px;">
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Transaction ID</td><td style="border-bottom:1px solid #eeeeee;">' . $transactionId . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Schedule ID</td><td style="border-bottom:1px solid #eeeeee;">' . $scheduleId . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Payment Stage</td><td style="border-bottom:1px solid #eeeeee;">' . $safeStage . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Owner</td><td style="border-bottom:1px solid #eeeeee;">' . $safeOwnerName . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Owner Email</td><td style="border-bottom:1px solid #eeeeee;">' . $safeOwnerEmail . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Service Request</td><td style="border-bottom:1px solid #eeeeee;">' . $safeRequestNo . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Offer No.</td><td style="border-bottom:1px solid #eeeeee;">' . $safeOfferNo . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">PSGA No.</td><td style="border-bottom:1px solid #eeeeee;">' . $safePSGANo . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Verified Amount</td><td style="border-bottom:1px solid #eeeeee;">₹' . $formattedAmount . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Payment Method</td><td style="border-bottom:1px solid #eeeeee;">' . $safeMethod . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">UTR / Transaction Reference</td><td style="border-bottom:1px solid #eeeeee;">' . $safeReference . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Payment Date</td><td style="border-bottom:1px solid #eeeeee;">' . $safePaymentDate . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Verified At</td><td style="border-bottom:1px solid #eeeeee;">' . $safeVerifiedAt . ' IST</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Total Amount</td><td style="border-bottom:1px solid #eeeeee;">₹' . $formattedTotal . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Total Received</td><td style="border-bottom:1px solid #eeeeee;">₹' . $formattedReceived . '</td></tr>
<tr><td style="font-weight:bold;border-bottom:1px solid #eeeeee;">Balance</td><td style="border-bottom:1px solid #eeeeee;">₹' . $formattedBalance . '</td></tr>
<tr><td style="font-weight:bold;">Payment Status</td><td>' . $safeStatus . '</td></tr>
</table>
<p style="margin-top:25px;font-size:14px;line-height:23px;">The payment verification has been completed successfully. The corresponding next payment stage has been updated according to the payment workflow.</p>
<p style="text-align:center;margin-top:25px;"><a href="' . $safeLoginUrl . '" style="display:inline-block;background:#198754;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;">Open Admin Dashboard</a></p>
</td>
</tr>
<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;">This is an automated email from the NLETA Maintenance Management System.<br>© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "NLETA Maintenance Management System\n\n"
            . "Payment Verified Successfully\n\n"
            . "Transaction ID: " . $transactionId . "\n"
            . "Schedule ID: " . $scheduleId . "\n"
            . "Payment Stage: " . ($paymentStage ?: '—') . "\n"
            . "Owner: " . ($ownerName ?: 'Owner') . "\n"
            . "Owner Email: " . ($ownerEmail ?: '—') . "\n"
            . "Service Request: " . ($requestNo ?: '—') . "\n"
            . "Offer No.: " . ($offerNo ?: '—') . "\n"
            . "PSGA No.: " . ($psgaNo ?: '—') . "\n"
            . "Verified Amount: ₹" . $formattedAmount . "\n"
            . "Payment Method: " . ($paymentMethod ?: '—') . "\n"
            . "UTR / Transaction Reference: " . ($transactionReference ?: '—') . "\n"
            . "Payment Date: " . ($paymentDate ?: '—') . "\n"
            . "Verified At: " . ($verifiedAt ?: '—') . " IST\n"
            . "Total Amount: ₹" . $formattedTotal . "\n"
            . "Total Received: ₹" . $formattedReceived . "\n"
            . "Balance: ₹" . $formattedBalance . "\n"
            . "Payment Status: " . ($paymentStatus ?: '—') . "\n\n"
            . "Open Admin Dashboard: " . NLETA_ADMIN_LOGIN_URL;

        $mail->send();
        return true;

    } catch (Throwable $e) {

        error_log(
            'NLETA Payment Verified Admin Email Error: '
            . $e->getMessage()
        );

        return false;
    }
}

/* =========================================================
   8. PSGA AGREEMENT SENT → OWNER
   ---------------------------------------------------------
   Sends the PSGA agreement notification only after Admin
   explicitly clicks "Send to Owner".

   IMPORTANT:
   - This function sends the email only.
   - PSGA status/database workflow is handled by the
     calling Admin PSGA module.
   - Owner receives the PSGA number and login link.
========================================================= */

function sendNletaPsgaOwnerEmail(
    int $psgaId,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string $offerNo = '',
    string $requestNo = ''
): bool {

    try {
        $ownerEmail = trim($ownerEmail);
        $ownerName  = trim($ownerName);
        $psgaNo     = trim($psgaNo);
        $offerNo    = trim($offerNo);
        $requestNo  = trim($requestNo);

        if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('NLETA PSGA Owner Email Error: Invalid Owner email address.');
            return false;
        }

        if (!class_exists('\\PHPMailer\\PHPMailer\\PHPMailer')) {
            error_log('NLETA PSGA Owner Email Error: PHPMailer class not available.');
            return false;
        }

        $mail = nletaCreateMailer();

        $mail->addAddress(
            $ownerEmail,
            $ownerName !== '' ? $ownerName : 'Owner'
        );

        $agreementDate = date('d-m-Y h:i A') . ' IST';

        $safeOwnerName = nletaSafe($ownerName !== '' ? $ownerName : 'Owner');
        $safePsgaNo    = nletaSafe($psgaNo !== '' ? $psgaNo : '—');
        $safeOfferNo   = nletaSafe($offerNo !== '' ? $offerNo : '—');
        $safeRequestNo = nletaSafe($requestNo !== '' ? $requestNo : '—');
        $safeDate      = nletaSafe($agreementDate);
        $safeLoginUrl  = nletaSafe(NLETA_OWNER_LOGIN_URL);

        $mail->Subject =
            'NLETA - PSGA Agreement Sent for Confirmation - ' .
            ($psgaNo !== '' ? $psgaNo : 'PSGA');

        $mail->isHTML(true);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA PSGA Agreement</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#1f2933;color:#ffffff;padding:24px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:6px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#d1d5db;">Maintenance Management System</div>
</td>
</tr>
<tr>
<td style="padding:30px;color:#222222;">
<h2 style="margin:0 0 18px;color:#1f2933;">PSGA Agreement Sent for Your Confirmation</h2>
<p style="font-size:15px;line-height:24px;">Dear <strong>' . $safeOwnerName . '</strong>,</p>
<p style="font-size:14px;line-height:23px;">
NLETA Admin has reviewed the Professional Service &amp; Governance Agreement (PSGA) and has sent it to you for review and confirmation.
</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safePsgaNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">FSO / Offer No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOfferNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service Request</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeRequestNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Sent Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeDate . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Status</td><td style="padding:10px;border:1px solid #e5e7eb;"><strong>Pending Owner Confirmation</strong></td></tr>
</table>
<div style="text-align:center;margin:28px 0;">
<a href="' . $safeLoginUrl . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:13px 24px;border-radius:6px;font-weight:bold;">Open Owner Dashboard</a>
</div>
<p style="font-size:14px;line-height:23px;">
Please log in to your Owner Dashboard, open <strong>Approvals</strong>, review the PSGA Agreement, and choose <strong>Accept</strong> or <strong>Reject</strong>.
</p>
<p style="font-size:13px;line-height:21px;color:#666666;">
This email was generated by the NLETA Maintenance Management System. Please do not reply to this automated notification.
</p>
</td>
</tr>
<tr>
<td style="background:#f8f9fa;border-top:1px solid #e5e7eb;padding:18px;text-align:center;font-size:12px;color:#777777;">
&copy; ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>
';

        $mail->AltBody =
            "Dear " . ($ownerName !== '' ? $ownerName : 'Owner') . ",\n\n" .
            "NLETA Admin has reviewed and sent the PSGA Agreement for your confirmation.\n\n" .
            "PSGA No.: " . ($psgaNo !== '' ? $psgaNo : '—') . "\n" .
            "FSO / Offer No.: " . ($offerNo !== '' ? $offerNo : '—') . "\n" .
            "Service Request: " . ($requestNo !== '' ? $requestNo : '—') . "\n" .
            "Sent Date: " . $agreementDate . "\n" .
            "Status: Pending Owner Confirmation\n\n" .
            "Please login to the Owner Dashboard, open Approvals, review the PSGA, and Accept or Reject it.\n\n" .
            "Owner Dashboard: " . NLETA_OWNER_LOGIN_URL;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log('NLETA PSGA Owner Email Error: ' . $e->getMessage());
        return false;
    }
}

/* =========================================================
   9. SAF ACCEPTED BY OWNER → ADMIN
   ---------------------------------------------------------
   Sends an email to Admin only after the Owner has
   successfully accepted the SAF through OTP verification.

   IMPORTANT:
   - Database acceptance is completed first.
   - Email failure MUST NOT undo the accepted SAF status.
========================================================= */

function sendNletaSafAcceptedAdminEmail(
    int $safId,
    string $safNo,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string $assetUniqueId,
    string $assetName,
    string|array $serviceName,
    string $proposedInspectionDate,
    string $acceptedAt
): bool {

    try {
        $mail = nletaCreateMailer();

        $mail->addAddress(
            NLETA_ADMIN_EMAIL,
            NLETA_ADMIN_NAME
        );

        $safeOwnerName = nletaSafe($ownerName ?: 'Owner');
        $safeOwnerEmail = nletaSafe($ownerEmail ?: '—');
        $safeSafNo = nletaSafe($safNo ?: '—');
        $safePsgaNo = nletaSafe($psgaNo ?: '—');
        $safeAssetId = nletaSafe($assetUniqueId ?: '—');
        $safeAssetName = nletaSafe($assetName ?: '—');
        $safeService = nletaServicesHtml($serviceName);
        $safeInspectionDate = nletaSafe($proposedInspectionDate ?: '—');
        $safeAcceptedAt = nletaSafe($acceptedAt ?: '—');
        $safeAdminUrl = nletaSafe(NLETA_ADMIN_LOGIN_URL);

        $mail->Subject =
            'NLETA - SAF Accepted by Owner - ' .
            ($safNo !== '' ? $safNo : 'SAF');

        $mail->isHTML(true);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA SAF Accepted</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#198754;color:#ffffff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#d1d5db;">Maintenance Management System</div>
</td>
</tr>
<tr>
<td style="padding:30px;color:#222222;">
<h2 style="margin:0 0 18px;color:#198754;">SAF Accepted by Owner</h2>
<p style="font-size:15px;line-height:24px;">
The Owner has successfully accepted the Service Activation Form (SAF) after OTP verification. Admin action may now continue with the existing post-acceptance inspection workflow.
</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">SAF ID</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safId . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">SAF No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeSafNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA / Contract No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safePsgaNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOwnerName . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner Email</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOwnerEmail . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Asset Registration / Unique ID</td><td style="padding:10px;border:1px solid #e5e7eb;"><strong>' . $safeAssetId . '</strong></td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Asset</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeAssetName . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeService . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Proposed Inspection Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeInspectionDate . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner Accepted At</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeAcceptedAt . ' IST</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Status</td><td style="padding:10px;border:1px solid #e5e7eb;color:#198754;font-weight:bold;">Accepted</td></tr>
</table>
<div style="margin-top:25px;padding:18px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;line-height:23px;">
<strong>Action Required</strong><br><br>
The Owner has accepted this SAF. Please open the Admin SAF section and continue the existing inspection assignment / post-acceptance workflow.
</div>
<div style="text-align:center;margin-top:28px;">
<a href="' . $safeAdminUrl . '" style="display:inline-block;background:#198754;color:#ffffff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;font-size:14px;">Open Admin Dashboard</a>
</div>
</td>
</tr>
<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;">This is an automated email from the NLETA Maintenance Management System.<br>© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "NLETA Maintenance Management System\n\n" .
            "SAF Accepted by Owner\n\n" .
            "SAF ID: " . $safId . "\n" .
            "SAF No.: " . ($safNo ?: '—') . "\n" .
            "PSGA / Contract No.: " . ($psgaNo ?: '—') . "\n" .
            "Owner: " . ($ownerName ?: 'Owner') . "\n" .
            "Owner Email: " . ($ownerEmail ?: '—') . "\n" .
            "Asset Registration / Unique ID: " . ($assetUniqueId ?: '—') . "\n" .
            "Asset: " . ($assetName ?: '—') . "\n" .
            "Service: " . ($serviceName ?: '—') . "\n" .
            "Proposed Inspection Date: " . ($proposedInspectionDate ?: '—') . "\n" .
            "Owner Accepted At: " . ($acceptedAt ?: '—') . " IST\n" .
            "Status: Accepted\n\n" .
            "Please continue the existing post-acceptance SAF workflow in the Admin Dashboard.\n" .
            NLETA_ADMIN_LOGIN_URL;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log(
            'NLETA SAF Accepted Admin Email Error: ' .
            $e->getMessage()
        );
        return false;
    }
}

/* =========================================================
   9. SAF INSPECTION DATE CHANGED → OWNER
   ---------------------------------------------------------
   Sends a notification when Admin changes the Proposed
   Inspection Date after the SAF has already been sent or
   accepted by the Owner.

   IMPORTANT:
   - This function only sends the email.
   - SAF status is handled by the Admin SAF module.
   - Old and new dates are shown to the Owner.
========================================================= */

function sendNletaSafInspectionDateChangedOwnerEmail(
    int $safId,
    string $safNo,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string $oldInspectionDate,
    string $newInspectionDate,
    string $changedAt
): bool {

    try {
        $ownerEmail = trim($ownerEmail);
        $ownerName  = trim($ownerName);

        if ($ownerEmail === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            error_log('NLETA SAF Date Change Email Error: Invalid Owner email address.');
            return false;
        }

        $mail = nletaCreateMailer();
        $mail->addAddress($ownerEmail, $ownerName !== '' ? $ownerName : 'Owner');
        $mail->Subject = 'NLETA - SAF Inspection Date Revised - ' . ($safNo ?: 'SAF');
        $mail->isHTML(true);

        $safeOwnerName = nletaSafe($ownerName !== '' ? $ownerName : 'Owner');
        $safeSafNo = nletaSafe($safNo !== '' ? $safNo : '—');
        $safePsgaNo = nletaSafe($psgaNo !== '' ? $psgaNo : '—');
        $safeOldDate = nletaSafe($oldInspectionDate !== '' ? $oldInspectionDate : '—');
        $safeNewDate = nletaSafe($newInspectionDate !== '' ? $newInspectionDate : '—');
        $safeChangedAt = nletaSafe($changedAt !== '' ? $changedAt : '—');
        $safeLoginUrl = nletaSafe(NLETA_OWNER_LOGIN_URL);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA SAF Inspection Date Revised</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#0d6efd;color:#ffffff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#dbeafe;">Maintenance Management System</div>
</td>
</tr>
<tr>
<td style="padding:30px;color:#222222;">
<h2 style="margin:0 0 18px;color:#0d6efd;">Proposed Inspection Date Revised</h2>
<p style="font-size:15px;line-height:24px;">Dear <strong>' . $safeOwnerName . '</strong>,</p>
<p style="font-size:14px;line-height:23px;">The Proposed Inspection Date for your Service Activation Form (SAF) has been changed by NLETA Admin following a scheduling request / operational requirement.</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">SAF No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeSafNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA / Contract No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safePsgaNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Previous Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOldDate . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Revised Proposed Inspection Date</td><td style="padding:10px;border:1px solid #e5e7eb;color:#0d6efd;font-weight:bold;">' . $safeNewDate . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Changed At</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeChangedAt . ' IST</td></tr>
</table>
<div style="margin-top:25px;padding:18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;line-height:23px;">
<strong>Please Note</strong><br><br>
The revised date is now the Proposed Inspection Date recorded against this SAF. Please use the Owner Dashboard for the latest SAF information.
</div>
<div style="text-align:center;margin-top:28px;">
<a href="' . $safeLoginUrl . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;font-size:14px;">Open Owner Dashboard</a>
</div>
</td>
</tr>
<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;">This is an automated email from the NLETA Maintenance Management System.<br>© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "Dear " . ($ownerName !== '' ? $ownerName : 'Owner') . ",\n\n" .
            "The Proposed Inspection Date for SAF " . ($safNo ?: '—') . " has been revised by NLETA Admin.\n\n" .
            "PSGA / Contract No.: " . ($psgaNo ?: '—') . "\n" .
            "Previous Date: " . ($oldInspectionDate ?: '—') . "\n" .
            "Revised Proposed Inspection Date: " . ($newInspectionDate ?: '—') . "\n" .
            "Changed At: " . ($changedAt ?: '—') . " IST\n\n" .
            "Please check the latest SAF information in the Owner Dashboard.\n" .
            NLETA_OWNER_LOGIN_URL;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log('NLETA SAF Date Change Owner Email Error: ' . $e->getMessage());
        return false;
    }
}



/* =========================================================
   OWNER DATE CHANGE REQUEST → ADMIN
========================================================= */
function sendNletaSafDateChangeRequestAdminEmail(
    int $requestId,
    int $safId,
    string $safNo,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string|array $serviceName,
    string $assetId,
    string $assetName,
    string $location,
    string $currentDate,
    string $requestedDate,
    string $reason,
    string $requestedAt
): bool {
    try {
        if (!filter_var(NLETA_ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $mail = nletaCreateMailer();
        $mail->addAddress(NLETA_ADMIN_EMAIL, NLETA_ADMIN_NAME);

        $mail->Subject =
            'NLETA - SAF Inspection Date Change Request - ' .
            ($safNo !== '' ? $safNo : 'SAF');

        $mail->isHTML(true);

        $safeSafNo = nletaSafe($safNo ?: '—');
        $safePsgaNo = nletaSafe($psgaNo ?: '—');
        $safeOwner = nletaSafe($ownerName ?: 'Owner');
        $safeOwnerEmail = nletaSafe($ownerEmail ?: '—');
        $safeService = nletaServicesHtml($serviceName);
        $safeAsset = nletaSafe($assetId ?: ($assetName ?: '—'));
        $safeLocation = nletaSafe($location ?: '—');
        $safeCurrent = nletaSafe($currentDate ?: 'Not scheduled');
        $safeRequested = nletaSafe($requestedDate ?: '—');
        $safeReason = nl2br(nletaSafe($reason ?: 'No reason provided.'));
        $safeRequestedAt = nletaSafe($requestedAt ?: '—');
        $safeLoginUrl = nletaSafe(NLETA_ADMIN_LOGIN_URL);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA SAF Date Change Request</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#fff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#f59e0b;color:#fff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#fef3c7;">Maintenance Management System</div>
</td>
</tr>
<tr>
<td style="padding:30px;color:#222;">
<h2 style="margin:0 0 18px;color:#b45309;">Owner Requested Inspection Date Change</h2>
<p style="font-size:14px;line-height:23px;">
An Owner has requested a change to the Proposed Inspection Date of an SAF.
Please review the request from the Admin Dashboard.
</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Request ID</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $requestId . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">SAF No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeSafNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safePsgaNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOwner . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Owner Email</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeOwnerEmail . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeService . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Asset</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeAsset . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Location</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeLocation . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Current Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeCurrent . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Requested New Date</td><td style="padding:10px;border:1px solid #e5e7eb;color:#b45309;font-weight:bold;">' . $safeRequested . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Requested At</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeRequestedAt . ' IST</td></tr>
</table>
<div style="margin-top:25px;padding:18px;background:#fffbeb;border:1px solid #fde68a;border-radius:7px;line-height:23px;">
<strong>Owner Reason</strong><br><br>' . $safeReason . '
</div>
<div style="text-align:center;margin-top:28px;">
<a href="' . $safeLoginUrl . '" style="display:inline-block;background:#0d6efd;color:#fff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;">Open Admin Dashboard</a>
</div>
</td>
</tr>
<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777;">
This is an automated email from the NLETA Maintenance Management System.<br>
© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.
</td>
</tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            "NLETA SAF Inspection Date Change Request\n\n" .
            "Request ID: " . $requestId . "\n" .
            "SAF No.: " . ($safNo ?: '—') . "\n" .
            "PSGA No.: " . ($psgaNo ?: '—') . "\n" .
            "Owner: " . ($ownerName ?: 'Owner') . "\n" .
            "Owner Email: " . ($ownerEmail ?: '—') . "\n" .
            "Service: " . ($serviceName ?: '—') . "\n" .
            "Asset: " . ($assetId ?: ($assetName ?: '—')) . "\n" .
            "Location: " . ($location ?: '—') . "\n" .
            "Current Date: " . ($currentDate ?: 'Not scheduled') . "\n" .
            "Requested New Date: " . ($requestedDate ?: '—') . "\n" .
            "Requested At: " . ($requestedAt ?: '—') . " IST\n\n" .
            "Reason:\n" . $reason . "\n\n" .
            "Admin Dashboard: " . NLETA_ADMIN_LOGIN_URL;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log('NLETA SAF Date Change Request Admin Email Error: ' . $e->getMessage());
        return false;
    }
}

/* =========================================================
   OWNER ACCEPTS OFFER → ADMIN
========================================================= */

function sendNletaOfferAcceptedAdminEmail(
    int $offerId,
    string $requestNo,
    string $offerNo,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string|array $serviceName,
    int $assetCount,
    float $grandTotal,
    string $acceptedAt
): bool {

    try {

        if (!filter_var(NLETA_ADMIN_EMAIL, FILTER_VALIDATE_EMAIL)) {
            error_log('NLETA Offer Accepted Admin Email Error: Invalid Admin email address.');
            return false;
        }

        $mail = nletaCreateMailer();

        $mail->addAddress(
            NLETA_ADMIN_EMAIL,
            NLETA_ADMIN_NAME
        );

        $mail->Subject =
            'NLETA - Offer Accepted by Owner - ' .
            ($offerNo !== '' ? $offerNo : 'Offer');

        $mail->isHTML(true);

        $serviceHtml = nletaServicesHtml($serviceName);
        $serviceText = nletaServicesText($serviceName);

        $safeOwnerName = nletaSafe($ownerName ?: 'Owner');
        $safeOwnerEmail = nletaSafe($ownerEmail ?: '—');
        $safeRequestNo = nletaSafe($requestNo ?: '—');
        $safeOfferNo = nletaSafe($offerNo ?: '—');
        $safePsgaNo = nletaSafe($psgaNo ?: '—');
        $safeAcceptedAt = nletaSafe($acceptedAt ?: '—');
        $safeAdminUrl = nletaSafe(NLETA_ADMIN_LOGIN_URL);

        $formattedTotal = number_format(
            $grandTotal,
            2,
            '.',
            ','
        );

        $currentYear =
            (new DateTime(
                'now',
                new DateTimeZone('Asia/Kolkata')
            ))->format('Y');

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Offer Accepted</title>
</head>

<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="background:#f4f6f9;padding:30px 10px;">

<tr>
<td align="center">

<table width="620" cellpadding="0" cellspacing="0" border="0"
style="max-width:620px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">

<tr>
<td style="background:#198754;color:#ffffff;padding:26px;text-align:center;">

<div style="font-size:26px;font-weight:bold;">
NLETA
</div>

<div style="margin-top:7px;font-size:14px;">
National Lift Escalator Testing Agency
</div>

<div style="margin-top:5px;font-size:12px;color:#d1fae5;">
Maintenance Management System
</div>

</td>
</tr>

<tr>
<td style="padding:30px;color:#222222;">

<h2 style="margin:0 0 18px;color:#198754;">
Offer Accepted by Owner
</h2>

<p style="font-size:15px;line-height:24px;">
The Owner has successfully accepted the NLETA Financial &amp; Service Offer (FSO-01).
</p>

<div style="margin:20px 0;padding:16px 18px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;line-height:23px;">

<strong style="color:#198754;">
Action Required
</strong>

<br><br>

Please open the Admin Dashboard and continue the applicable workflow.

</div>

<table width="100%" cellpadding="0" cellspacing="0" border="0"
style="border-collapse:collapse;margin-top:20px;">

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;width:42%;">
Offer ID
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $offerId . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Service Request
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $safeRequestNo . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
FSO-01 Offer No.
</td>
<td style="padding:10px;border:1px solid #e5e7eb;font-weight:bold;">
' . $safeOfferNo . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
PSGA / Contract No.
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $safePsgaNo . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Owner
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $safeOwnerName . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Owner Email
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $safeOwnerEmail . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Service(s)
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $serviceHtml . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Selected Assets
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . (int)$assetCount . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Grand Total
</td>
<td style="padding:10px;border:1px solid #e5e7eb;color:#198754;font-weight:bold;">
₹' . $formattedTotal . '
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Accepted At
</td>
<td style="padding:10px;border:1px solid #e5e7eb;">
' . $safeAcceptedAt . ' IST
</td>
</tr>

<tr>
<td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">
Status
</td>
<td style="padding:10px;border:1px solid #e5e7eb;color:#198754;font-weight:bold;">
Offer Accepted by Owner
</td>
</tr>

</table>

<div style="text-align:center;margin-top:28px;">

<a href="' . $safeAdminUrl . '"
style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;font-size:14px;">
Open Admin Dashboard
</a>

</div>

<p style="margin-top:25px;font-size:13px;line-height:21px;color:#6b7280;">
This is an automated notification from the NLETA Maintenance Management System.
</p>

</td>
</tr>

<tr>
<td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;line-height:18px;">
© ' . $currentYear . ' NLETA. All Rights Reserved.
</td>
</tr>

</table>

</td>
</tr>
</table>

</body>
</html>';

        $mail->AltBody =
            "NLETA Maintenance Management System\n\n" .
            "OFFER ACCEPTED BY OWNER\n\n" .
            "Offer ID: " . $offerId . "\n" .
            "Service Request: " . ($requestNo ?: '—') . "\n" .
            "FSO-01 Offer No.: " . ($offerNo ?: '—') . "\n" .
            "PSGA / Contract No.: " . ($psgaNo ?: '—') . "\n" .
            "Owner: " . ($ownerName ?: 'Owner') . "\n" .
            "Owner Email: " . ($ownerEmail ?: '—') . "\n" .
            "Service(s): " . $serviceText . "\n" .
            "Selected Assets: " . $assetCount . "\n" .
            "Grand Total: ₹" . $formattedTotal . "\n" .
            "Accepted At: " . ($acceptedAt ?: '—') . " IST\n" .
            "Status: Offer Accepted by Owner\n\n" .
            "Please continue the applicable workflow from the Admin Dashboard.\n" .
            NLETA_ADMIN_LOGIN_URL;

        $mail->send();

        return true;

    } catch (Throwable $e) {

        error_log(
            'NLETA Offer Accepted Admin Email Error: ' .
            $e->getMessage()
        );

        return false;
    }
}



/* =========================================================
   10. INSPECTOR ASSIGNED / REASSIGNED → OWNER
   ---------------------------------------------------------
   Sends Inspector details to the Owner after Admin successfully
   assigns or reassigns an Inspector for an Accepted SAF.

   IMPORTANT:
   - Database transaction is completed before this function is called.
   - Email failure MUST NOT rollback the assignment.
   - Supports multiple asset-wise assignments in one email.
========================================================= */

function sendNletaSafInspectorAssignmentOwnerEmail(
    int $safId,
    string $safNo,
    string $psgaNo,
    string $ownerName,
    string $ownerEmail,
    string|array $serviceName,
    string $inspectionDate,
    array $assignments,
    bool $isReassignment = false
): bool {

    try {
        $ownerEmail = trim($ownerEmail);
        $ownerName  = trim($ownerName);

        if (
            $ownerEmail === '' ||
            !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)
        ) {
            error_log(
                'NLETA Inspector Assignment Owner Email Error: Invalid Owner email.'
            );
            return false;
        }

        $mail = nletaCreateMailer();

        $mail->addAddress(
            $ownerEmail,
            $ownerName !== '' ? $ownerName : 'Owner'
        );

        $serviceText = '';

        if (is_array($serviceName)) {
            $serviceParts = [];

            foreach ($serviceName as $service) {
                $service = trim((string)$service);
                if ($service !== '' && !in_array($service, $serviceParts, true)) {
                    $serviceParts[] = $service;
                }
            }

            $serviceText = implode(', ', $serviceParts);
        } else {
            $serviceText = trim($serviceName);
        }

        if ($serviceText === '') {
            $serviceText = '—';
        }

        $safeOwnerName = nletaSafe(
            $ownerName !== '' ? $ownerName : 'Owner'
        );
        $safeSafNo = nletaSafe($safNo !== '' ? $safNo : '—');
        $safePsgaNo = nletaSafe($psgaNo !== '' ? $psgaNo : '—');
        $safeService = nletaSafe($serviceText);
        $safeInspectionDate = nletaSafe(
            $inspectionDate !== '' ? $inspectionDate : '—'
        );

        $updatedAt = (new DateTime(
            'now',
            new DateTimeZone('Asia/Kolkata')
        ))->format('d-m-Y h:i A') . ' IST';

        $safeUpdatedAt = nletaSafe($updatedAt);

        $heading = $isReassignment
            ? 'Inspector Assignment Revised'
            : 'Inspector Assigned';

        $intro = $isReassignment
            ? 'NLETA Admin has revised the Inspector assignment for your SAF.'
            : 'NLETA Admin has assigned an Inspector for your SAF.';

        $htmlRows = '';
        $textRows = '';

        foreach ($assignments as $assignment) {

            $assetId = trim(
                (string)($assignment['asset_id'] ?? '')
            );

            $displayAssetId = trim(
                (string)($assignment['display_asset_id'] ?? '')
            );

            $assetName = trim(
                (string)($assignment['asset_name'] ?? '')
            );

            $location = trim(
                (string)($assignment['location'] ?? '')
            );

            $inspectorName = trim(
                (string)($assignment['inspector_name'] ?? '')
            );

            $inspectorEmail = trim(
                (string)($assignment['inspector_email'] ?? '')
            );

            $inspectorPhone = trim(
                (string)($assignment['inspector_phone'] ?? '')
            );

            $assetLabel = $displayAssetId !== ''
                ? $displayAssetId
                : ($assetId !== '' ? 'Asset #' . $assetId : '—');

            $htmlRows .= '
                <tr>
                    <td style="padding:10px;border:1px solid #e5e7eb;vertical-align:top;">
                        <strong>' . nletaSafe($assetLabel) . '</strong>' .
                        ($assetName !== ''
                            ? '<br><span style="font-size:12px;color:#6b7280;">' .
                              nletaSafe($assetName) .
                              '</span>'
                            : '') .
                    '</td>
                    <td style="padding:10px;border:1px solid #e5e7eb;vertical-align:top;">' .
                        nletaSafe($location !== '' ? $location : '—') .
                    '</td>
                    <td style="padding:10px;border:1px solid #e5e7eb;vertical-align:top;">
                        <strong>' .
                            nletaSafe($inspectorName !== '' ? $inspectorName : 'Inspector') .
                        '</strong>' .
                        ($inspectorEmail !== ''
                            ? '<br><span style="font-size:12px;">' .
                              nletaSafe($inspectorEmail) .
                              '</span>'
                            : '') .
                        ($inspectorPhone !== ''
                            ? '<br><span style="font-size:12px;">Mobile: ' .
                              nletaSafe($inspectorPhone) .
                              '</span>'
                            : '') .
                    '</td>
                </tr>';

            $textRows .=
                'Asset: ' . $assetLabel . "\n" .
                'Asset Name: ' . ($assetName !== '' ? $assetName : '—') . "\n" .
                'Location: ' . ($location !== '' ? $location : '—') . "\n" .
                'Inspector: ' . ($inspectorName !== '' ? $inspectorName : 'Inspector') . "\n" .
                'Inspector Email: ' . ($inspectorEmail !== '' ? $inspectorEmail : '—') . "\n" .
                'Inspector Mobile: ' . ($inspectorPhone !== '' ? $inspectorPhone : '—') . "\n\n";
        }

        if ($htmlRows === '') {
            $htmlRows = '
                <tr>
                    <td colspan="3" style="padding:12px;border:1px solid #e5e7eb;color:#777;">
                        Assignment details are not available.
                    </td>
                </tr>';
        }

        $ownerUrl = defined('NLETA_OWNER_LOGIN_URL')
            ? NLETA_OWNER_LOGIN_URL
            : '#';

        $mail->Subject =
            'NLETA - Inspector ' .
            ($isReassignment ? 'Reassigned' : 'Assigned') .
            ' - SAF ' .
            ($safNo !== '' ? $safNo : 'SAF');

        $mail->isHTML(true);

        $mail->Body = '
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NLETA Inspector Assignment</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:Arial,Helvetica,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:30px 10px;">
<tr><td align="center">
<table width="620" cellpadding="0" cellspacing="0" border="0" style="max-width:620px;width:100%;background:#ffffff;border:1px solid #e1e5ea;border-radius:10px;overflow:hidden;">
<tr>
<td style="background:#0d6efd;color:#ffffff;padding:25px;text-align:center;">
<div style="font-size:25px;font-weight:bold;letter-spacing:.5px;">NLETA</div>
<div style="margin-top:7px;font-size:14px;">National Lift Escalator Testing Agency</div>
<div style="margin-top:5px;font-size:12px;color:#dbeafe;">Maintenance Management System</div>
</td>
</tr>
<tr><td style="padding:30px;color:#222222;">
<h2 style="margin:0 0 18px;color:#0d6efd;font-size:20px;">' . $heading . '</h2>
<p style="font-size:14px;line-height:23px;margin:0 0 18px;">Dear <strong>' . $safeOwnerName . '</strong>,</p>
<p style="font-size:14px;line-height:23px;">' . $intro . '</p>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-top:20px;">
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">SAF No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeSafNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">PSGA / Contract No.</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safePsgaNo . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Service</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeService . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Proposed Inspection Date</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeInspectionDate . '</td></tr>
<tr><td style="padding:10px;background:#f8f9fa;border:1px solid #e5e7eb;font-weight:bold;">Assignment Updated</td><td style="padding:10px;border:1px solid #e5e7eb;">' . $safeUpdatedAt . '</td></tr>
</table>
<h3 style="margin:25px 0 12px;color:#172033;font-size:16px;">Inspector Details</h3>
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">
<tr>
<th style="padding:10px;background:#f1f5f9;border:1px solid #e5e7eb;text-align:left;">Asset</th>
<th style="padding:10px;background:#f1f5f9;border:1px solid #e5e7eb;text-align:left;">Location</th>
<th style="padding:10px;background:#f1f5f9;border:1px solid #e5e7eb;text-align:left;">Inspector</th>
</tr>
' . $htmlRows . '
</table>
<div style="margin-top:25px;padding:18px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;line-height:23px;">
<strong>Important</strong><br><br>
Please use your Owner Dashboard to view the latest SAF status and inspection information.
</div>
<div style="text-align:center;margin-top:28px;">
<a href="' . nletaSafe($ownerUrl) . '" style="display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;padding:13px 25px;border-radius:6px;font-weight:bold;font-size:14px;">Open Owner Dashboard</a>
</div>
<p style="margin-top:25px;font-size:13px;line-height:21px;color:#6b7280;">This is an automated notification from the NLETA Maintenance Management System. Please do not reply to this email.</p>
</td></tr>
<tr><td style="background:#f4f6f9;padding:18px;text-align:center;font-size:12px;color:#777777;">This is an automated email from the NLETA Maintenance Management System.<br>© ' . (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y') . ' NLETA. All Rights Reserved.</td></tr>
</table>
</td></tr>
</table>
</body>
</html>';

        $mail->AltBody =
            'NLETA Maintenance Management System' . "\n\n" .
            $heading . "\n\n" .
            'Dear ' . ($ownerName !== '' ? $ownerName : 'Owner') . ",\n\n" .
            $intro . "\n\n" .
            'SAF No.: ' . ($safNo !== '' ? $safNo : '—') . "\n" .
            'PSGA / Contract No.: ' . ($psgaNo !== '' ? $psgaNo : '—') . "\n" .
            'Service: ' . $serviceText . "\n" .
            'Proposed Inspection Date: ' . ($inspectionDate !== '' ? $inspectionDate : '—') . "\n" .
            'Assignment Updated: ' . $updatedAt . "\n\n" .
            'Inspector Details' . "\n" .
            '-----------------' . "\n" .
            $textRows .
            "\nOwner Dashboard: " . $ownerUrl;

        $mail->send();
        return true;

    } catch (Throwable $e) {
        error_log(
            'NLETA Inspector Assignment Owner Email Error: ' .
            $e->getMessage()
        );
        return false;
    }
}
