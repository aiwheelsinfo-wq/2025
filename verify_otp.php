<?php
include('db_connect.php');

// Get the phone number and OTP from the POST request
$phoneNumber = $_POST['phone_number'];
$otp = $_POST['otp'];

// Query to fetch the OTP and sent time from the database
$query = "SELECT * FROM users WHERE phone_number = '$phoneNumber' AND otp = '$otp'";
$result = mysqli_query($conn, $query);
$row = mysqli_fetch_assoc($result);

if ($row) {
    // OTP found in the database, now check if it's expired (5 minutes validity)
    $otp_sent_time = $row['otp_sent_time'];
    $current_time = date('Y-m-d H:i:s');
    $time_diff = strtotime($current_time) - strtotime($otp_sent_time);

    if ($time_diff <= 300) { // 5 minutes = 300 seconds
        echo "OTP Verified successfully!";
    } else {
        echo "OTP expired!";
    }
} else {
    echo "Invalid OTP!";
}

mysqli_close($conn);
?>
