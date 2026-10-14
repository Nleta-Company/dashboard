<?php
// SMS Debug Test - DELETE THIS FILE AFTER TESTING
$apiKey = 'K8lh0rqTzSQC05Ioi9Xhl5uj0azDJvAE31NQae4HYp11N9TrpHF1RzoVZc31';
$phone  = '9322506727';
$msg    = 'NLETA Test SMS';

echo "<h3>SMS Debug Test</h3>";

echo "cURL enabled: " . (function_exists('curl_init') ? '<b style="color:green">YES</b>' : '<b style="color:red">NO</b>') . "<br>";

$url  = 'https://www.fast2sms.com/dev/bulkV2';
$data = http_build_query([
    'message'  => $msg,
    'language' => 'english',
    'route'    => 'q',
    'numbers'  => $phone,
    'flash'    => 0,
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $data,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'authorization: ' . $apiKey,
        'Content-Type: application/x-www-form-urlencoded',
        'Cache-Control: no-cache',
    ],
]);
$response  = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

echo "cURL Error: " . ($curlError ? '<b style="color:red">'.$curlError.'</b>' : '<b style="color:green">None</b>') . "<br>";
echo "API Response: <pre>" . print_r(json_decode($response, true), true) . "</pre>";
