<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$apiKey = 'p9J1ofaxrnDXePcsUTdlRu630Vg7KQiWMC24OEmjwFSByh8AH5R5n6sSBzCuvQATbf2g87hV9mtqd0GD';

// Test 1: bulkV2 POST JSON
echo "Testing bulkV2 (POST JSON):\n";
$url = 'https://www.fast2sms.com/dev/bulkV2';
$payload = [
    'route' => 'q',
    'message' => 'SMS Diagnostic Test - Agni Car Rental',
    'language' => 'english',
    'flash' => 0,
    'numbers' => '7977098244'
];
$headers = [
    'authorization: ' . $apiKey,
    'Content-Type: application/json'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res1 = curl_exec($ch);
$http_code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $http_code1\n";
echo "Response: $res1\n\n";

// Test 2: bulkV2 GET
echo "Testing bulkV2 (GET query params):\n";
$msg = urlencode('SMS Diagnostic Test - Agni Car Rental');
$url2 = "https://www.fast2sms.com/dev/bulkV2?authorization=$apiKey&route=q&message=$msg&flash=0&numbers=7977098244";
$ch2 = curl_init($url2);
curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
$res2 = curl_exec($ch2);
$http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

echo "HTTP Code: $http_code2\n";
echo "Response: $res2\n\n";
?>
