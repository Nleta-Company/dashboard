<?php
function sendEmail(string $toEmail, string $toName, string $subject, string $body): bool {
    $smtpHost = 'ssl://smtp.gmail.com';
    $smtpPort = 465;
    $smtpUser = 'inspnleta@gmail.com';
    $smtpPass = 'cfzvbhreqdarbtig';

    $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
    if (!$socket) return false;

    // Helper to read full multi-line response
    $read = function() use ($socket) {
        $resp = '';
        while ($line = fgets($socket, 512)) {
            $resp .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        return $resp;
    };

    $read(); // greeting
    fputs($socket, "EHLO smtp.gmail.com\r\n"); $read(); // EHLO
    fputs($socket, "AUTH LOGIN\r\n");           $read(); // 334
    fputs($socket, base64_encode($smtpUser) . "\r\n"); $read(); // 334
    fputs($socket, base64_encode($smtpPass) . "\r\n");
    $authResp = $read();

    if (strpos($authResp, '235') === false) { fclose($socket); return false; }

    $htmlBody = nl2br(htmlspecialchars($body));
    $message  = "<html><body style='font-family:Arial,sans-serif;font-size:14px;color:#333;'>
        <div style='max-width:500px;margin:auto;border:1px solid #ddd;border-radius:8px;padding:24px;'>
        <h2 style='color:#0d6efd;'>NLETA Maintenance System</h2>
        <p>$htmlBody</p>
        <hr style='border:none;border-top:1px solid #eee;'>
        <small style='color:#999;'>This is an automated message from NLETA Dashboard.</small>
        </div></body></html>";

    fputs($socket, "MAIL FROM:<$smtpUser>\r\n");  $read();
    fputs($socket, "RCPT TO:<$toEmail>\r\n");       $read();
    fputs($socket, "DATA\r\n");                     $read();

    $headers  = "From: NLETA <$smtpUser>\r\n";
    $headers .= "To: $toName <$toEmail>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    fputs($socket, $headers . "\r\n" . $message . "\r\n.\r\n");
    $dataResp = $read();

    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return strpos($dataResp, '250') !== false;
}
