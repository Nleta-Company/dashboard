<?php
require '../config/db.php';
require '../config/auth.php';
require_role('admin');

$otp = rand(100000, 999999);
$_SESSION['delete_otp'] = $otp;
$_SESSION['otp_time']   = time();

$apiKey  = getenv('FAST2SMS_API_KEY');
$phone   = getenv('ADMIN_PHONE');

if (!$apiKey || !$phone) {
    echo json_encode(['message' => 'OTP service not configured. Contact administrator.']);
    exit;
}

$message = urlencode("Your NLETA deletion OTP is: $otp. Valid for 5 minutes.");
$url = "https://www.fast2sms.com/dev/bulkV2?authorization=" . urlencode($apiKey) . "&route=q&message=$message&language=english&flash=0&numbers=" . urlencode($phone);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if (isset($result['return']) && $result['return'] === true) {
    echo json_encode(['message' => 'OTP sent to registered number.']);
} else {
    echo json_encode(['message' => 'Failed to send OTP. Try again.']);
}
