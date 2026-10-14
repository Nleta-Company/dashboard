<?php
$smtpHost = 'ssl://smtp.gmail.com';
$smtpPort = 465;
$smtpUser = 'inspnleta@gmail.com';
$smtpPass = 'cfzvbhreqdarbtig';
$toEmail  = 'inspnleta@gmail.com';

echo "<h3>Email Debug Test</h3>";

$socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
if (!$socket) { echo "<b style='color:red'>Cannot connect: $errstr</b>"; exit; }
echo "Connected!<br>";

// Read greeting
$resp = '';
while ($line = fgets($socket, 512)) {
    $resp .= $line;
    if (substr($line, 3, 1) === ' ') break;
}
echo "Greeting: " . htmlspecialchars($resp) . "<br>";

// EHLO
fputs($socket, "EHLO smtp.gmail.com\r\n");
$resp = '';
while ($line = fgets($socket, 512)) {
    $resp .= $line;
    if (substr($line, 3, 1) === ' ') break;
}
echo "EHLO: " . htmlspecialchars($resp) . "<br>";

// AUTH LOGIN
fputs($socket, "AUTH LOGIN\r\n");
$resp = fgets($socket, 512);
echo "AUTH: " . htmlspecialchars($resp) . "<br>";

// Username
fputs($socket, base64_encode($smtpUser) . "\r\n");
$resp = fgets($socket, 512);
echo "User: " . htmlspecialchars($resp) . "<br>";

// Password
fputs($socket, base64_encode($smtpPass) . "\r\n");
$resp = fgets($socket, 512);
echo "Pass: " . htmlspecialchars($resp) . "<br>";

if (strpos($resp, '235') !== false) {
    echo "<b style='color:green'>Auth SUCCESS!</b><br>";

    fputs($socket, "MAIL FROM:<$smtpUser>\r\n");
    $r1 = fgets($socket, 512);
    fputs($socket, "RCPT TO:<$toEmail>\r\n");
    $r2 = fgets($socket, 512);
    fputs($socket, "DATA\r\n");
    $r3 = fgets($socket, 512);

    $msg  = "From: NLETA <$smtpUser>\r\n";
    $msg .= "To: <$toEmail>\r\n";
    $msg .= "Subject: NLETA Test\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $msg .= "This is a test email from NLETA.\r\n.\r\n";
    fputs($socket, $msg);
    $r4 = fgets($socket, 512);

    echo "Result: " . htmlspecialchars($r4) . "<br>";
    echo strpos($r4, '250') !== false
        ? "<b style='color:green'>Email SENT!</b>"
        : "<b style='color:red'>Send failed.</b>";
} else {
    echo "<b style='color:red'>Auth FAILED.</b>";
}

fputs($socket, "QUIT\r\n");
fclose($socket);
