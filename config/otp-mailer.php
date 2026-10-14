<?php

/**
 * ============================================================
 * NLETA OTP MAILER
 * ============================================================
 *
 * Used for:
 * 1. Signup email verification
 * 2. Password reset
 * 3. SAF Contract acceptance
 *
 * Project:
 * NLETA Unified Digital Dashboard
 * ============================================================
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/* ============================================================
   PHPMailer
============================================================ */

require_once __DIR__ . '/../vendor/autoload.php';


/* ============================================================
   SMTP CONFIGURATION
============================================================ */

const OTP_SMTP_HOST = 'smtp.gmail.com';

const OTP_SMTP_PORT = 587;

/*
 * IMPORTANT:
 *
 * Use Gmail App Password.
 * Do NOT use your normal Gmail password.
 */
const OTP_SMTP_USERNAME = 'info@nleta.org.in';

const OTP_SMTP_PASSWORD = 'hzfqrubdeyielrjm';


/* ============================================================
   SENDER
============================================================ */

const OTP_FROM_EMAIL = 'info@nleta.org.in';

const OTP_FROM_NAME = 'NLETA';


/* ============================================================
   OTP SETTINGS
============================================================ */

const OTP_EXPIRY_MINUTES = 5;

const OTP_RESEND_SECONDS = 60;

const OTP_MAX_ATTEMPTS = 5;


/* ============================================================
   SEND NLETA OTP EMAIL
============================================================ */

function sendNletaOtpEmail(
    string $recipientEmail,
    string $recipientName,
    string $otp,
    string $purpose
): bool {

    $recipientEmail = trim($recipientEmail);
    $recipientName  = trim($recipientName);
    $otp            = trim($otp);
    $purpose        = trim($purpose);


    if ($recipientEmail === '') {
        error_log('NLETA OTP: Recipient email is empty.');
        return false;
    }


    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        error_log(
            'NLETA OTP: Invalid recipient email.'
        );

        return false;
    }


    if ($otp === '') {
        error_log('NLETA OTP: OTP is empty.');
        return false;
    }


    /* ========================================================
       DETERMINE PURPOSE
    ======================================================== */

    switch ($purpose) {

        case 'signup':

            $subject =
                'NLETA - Verify Your Email Address';

            $heading =
                'Email Verification';

            $message =
                'Thank you for creating your NLETA account. Please use the OTP below to verify your email address.';

            break;


        case 'password_reset':

            $subject =
                'NLETA - Password Reset OTP';

            $heading =
                'Password Reset';

            $message =
                'A password reset request was made for your NLETA account. Use the OTP below to continue.';

            break;


        case 'saf_acceptance':

            $subject =
                'NLETA - SAF Acceptance Verification OTP';

            $heading =
                'SAF Acceptance Verification';

            $message =
                'You have requested to accept an NLETA Service Activation Form (SAF). Please use the OTP below to verify and confirm your acceptance.';

            break;


        default:

            error_log(
                'NLETA OTP: Invalid purpose: ' .
                $purpose
            );

            return false;
    }


    /* ========================================================
       SAFE VALUES
    ======================================================== */

    $safeName = htmlspecialchars(
        $recipientName !== ''
            ? $recipientName
            : 'Owner',
        ENT_QUOTES,
        'UTF-8'
    );


    $safeOtp = htmlspecialchars(
        $otp,
        ENT_QUOTES,
        'UTF-8'
    );


    $mail = new PHPMailer(true);


    try {

        /* ====================================================
           SMTP
        ==================================================== */

        $mail->isSMTP();

        $mail->Host =
            OTP_SMTP_HOST;

        $mail->SMTPAuth =
            true;

        $mail->Username =
            OTP_SMTP_USERNAME;

        $mail->Password =
            OTP_SMTP_PASSWORD;

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port =
            OTP_SMTP_PORT;


        /* ====================================================
           CHARACTER SET
        ==================================================== */

        $mail->CharSet = 'UTF-8';


        /* ====================================================
           SENDER
        ==================================================== */

        $mail->setFrom(
            OTP_FROM_EMAIL,
            OTP_FROM_NAME
        );


        /* ====================================================
           RECIPIENT
        ==================================================== */

        $mail->addAddress(
            $recipientEmail,
            $recipientName !== ''
                ? $recipientName
                : 'Owner'
        );


        /* ====================================================
           SUBJECT
        ==================================================== */

        $mail->Subject =
            $subject;


        /* ====================================================
           HTML
        ==================================================== */

        $mail->isHTML(true);


        $mail->Body = '
<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>NLETA OTP</title>

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
border-radius:10px;
overflow:hidden;
"
>

<tr>

<td
align="center"
style="
background:#1f2933;
padding:25px 20px;
color:#ffffff;
"
>

<div
style="
font-size:24px;
font-weight:bold;
letter-spacing:1px;
"
>
NLETA
</div>

<div
style="
margin-top:8px;
font-size:14px;
line-height:20px;
"
>
NATIONAL LIFT ESCALATOR
<br>
TESTING AGENCY
</div>

</td>

</tr>


<tr>

<td
style="
padding:35px 30px;
color:#222222;
"
>

<h2
style="
margin:0 0 20px 0;
font-size:22px;
color:#1f2933;
"
>
' . $heading . '
</h2>


<p
style="
font-size:15px;
line-height:24px;
margin:0 0 15px 0;
"
>

Dear <strong>' . $safeName . '</strong>,

</p>


<p
style="
font-size:15px;
line-height:24px;
margin:0 0 20px 0;
"
>

' . $message . '

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
>

<tr>

<td align="center">

<div
style="
display:inline-block;
background:#ea232a;
color:#ffffff;
padding:16px 28px;
border-radius:8px;
font-size:30px;
font-weight:bold;
letter-spacing:8px;
min-width:180px;
text-align:center;
"
>

' . $safeOtp . '

</div>

</td>

</tr>

</table>


<p
style="
margin:25px 0 10px 0;
font-size:15px;
line-height:24px;
"
>

<strong>
This OTP is valid for 5 minutes.
</strong>

</p>


<p
style="
margin:0 0 20px 0;
font-size:14px;
line-height:22px;
color:#555555;
"
>

For your security, do not share this OTP with anyone.

</p>


<table
width="100%"
cellpadding="0"
cellspacing="0"
border="0"
style="
background:#fff4f4;
border-left:4px solid #ea232a;
margin-top:20px;
"
>

<tr>

<td
style="
padding:14px 15px;
font-size:13px;
line-height:20px;
color:#555555;
"
>

If you did not request this verification code,
you can safely ignore this email.

</td>

</tr>

</table>


</td>

</tr>


<tr>

<td
align="center"
style="
background:#f4f6f9;
padding:18px;
color:#777777;
font-size:12px;
line-height:18px;
"
>

© ' . date('Y') . '
NLETA. All Rights Reserved.

<br>

This is an automated email.
Please do not reply.

</td>

</tr>


</table>

</td>

</tr>

</table>

</body>

</html>
';


        /* ====================================================
           PLAIN TEXT
        ==================================================== */

        $mail->AltBody =
            "NLETA - {$heading}\n\n" .
            "Dear {$recipientName},\n\n" .
            "{$message}\n\n" .
            "Your OTP is: {$otp}\n\n" .
            "This OTP is valid for 5 minutes.\n" .
            "Do not share this OTP with anyone.\n\n" .
            "If you did not request this code, you can safely ignore this email.\n\n" .
            "© " . date('Y') .
            " NLETA. All Rights Reserved.";


        /* ====================================================
           SEND
        ==================================================== */

        $mail->send();

        return true;

    } catch (Exception $e) {

        error_log(
            'NLETA OTP Mail Error: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}