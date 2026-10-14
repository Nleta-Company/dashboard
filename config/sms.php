<?php
function sendSMS(string $phone, string $message): bool {
    $apiKey = 'K8lh0rqTzSQC05Ioi9Xhl5uj0azDJvAE31NQae4HYp11N9TrpHF1RzoVZc31';
    if (!$apiKey) return false;

    $phone = preg_replace('/\D/', '', $phone);
    if (strlen($phone) < 10) return false;
    $phone = substr($phone, -10);

    $url  = 'https://www.fast2sms.com/dev/bulkV2';
    $data = http_build_query([
        'message'  => $message,
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
            'authorization: K8lh0rqTzSQC05Ioi9Xhl5uj0azDJvAE31NQae4HYp11N9TrpHF1RzoVZc31',
            'Content-Type: application/x-www-form-urlencoded',
            'Cache-Control: no-cache',
        ],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return isset($result['return']) && $result['return'] === true;
}
