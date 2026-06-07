<?php
include('db_connect.php');

// Fast2SMS API credentials
$apiKey = 'p9J1ofaxrnDXePcsUTdlRu630Vg7KQiWMC24OEmjwFSByh8AH5R5n6sSBzCuvQATbf2g87hV9mtqd0GD'; // Replace with your Fast2SMS API key

// Get phone number from the POST request
 $phoneNumber = $_POST['phone_number'];


// Generate a 6-digit OTP
$otp = rand(100000, 999999);

// Send OTP via Fast2SMS API
$url = "https://www.fast2sms.com/dev/api/SendSMS";
$payload = [
    'sender_id' => 'FSTSMS',
    'message' => "Your OTP is: $otp",
    'language' => 'english',
    'route' => 'p',
    'numbers' => $phoneNumber,
    'flash' => 0
];
$headers = [
    'Authorization' => "Bearer $apiKey"
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$response = curl_exec($ch);
curl_close($ch);

// Save the phone number and OTP in the database
$otp_sent_time = date('Y-m-d H:i:s');
$query = "INSERT INTO users (phone_number, otp, otp_sent_time) VALUES ('$phoneNumber', '$otp', '$otp_sent_time')";
$result = mysqli_query($conn, $query);

if ($result) {
    echo "OTP sent successfully";
} else {
    echo "Error: " . mysqli_error($conn);
}

mysqli_close($conn);
?>
