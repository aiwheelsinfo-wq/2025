<?php
$data = [
    'booking_number' => '9847267465',
    'phone_number' => '9847267465',
    'name' => 'Test User',
    'email' => 'test@test.com',
    'city' => 'Kochi',
    'pincode' => '682001',
    'from_address' => 'Kochi, Kerala, India',
    'to_address' => 'Aluva, Kerala, India',
    'car_type' => 'Sedan',
    'total_amount' => 500.0,
    'distance' => 15.0
];

$_POST = [];
$json = json_encode($data);

// Simulate php://input by writing to a temp file and reading it if needed,
// but since save_Local_taxi_booking_and_customer.php reads php://input,
// we can test it using curl from the command line by creating a local JSON file first on the server.
file_put_contents('/tmp/payload.json', $json);
echo "Payload written to /tmp/payload.json\n";
?>
