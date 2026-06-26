<?php
$data = [
    'booking_number' => '9847267465',
    'phone_number' => '9847267465',
    'name' => 'Test User',
    'email' => 'test@test.com',
    'city' => 'Kochi',
    'pincode' => '682001',
    'from_address' => 'Shop no 1, Mumbai, Maharashtra',
    'to_address' => 'Thane, Maharashtra, India',
    'car_type' => 'Sedan',
    'total_amount' => 387.50,
    'distance' => 10.0
];

$ch = curl_init('https://localhost/2025/save_Local_taxi_booking_and_customer.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
} else {
    echo "=== RESPONSE ===\n";
    echo $response . "\n";
    echo "=== LENGTH: " . strlen($response) . " ===\n";
}
curl_close($ch);
?>
