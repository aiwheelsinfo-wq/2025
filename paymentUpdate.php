<?php
include 'db_connect.php';

$response = array();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $payment_id = $_POST['payment_id'];
    $user_mobile = $_POST['user_mobile'];  // Identify the correct booking
    $amount = $_POST['amount'];
    $status = $_POST['status'];

    // Update the booking record with payment details
    $sql = "UPDATE bookings SET 
                payment_id = '$payment_id', 
                payment_status = '$status', 
                paid_amount = '$amount' 
            WHERE mobile = '$user_mobile' 
            ORDER BY id DESC LIMIT 1"; // Update the latest booking of this user

    if (mysqli_query($conn, $sql)) {
        $response["success"] = true;
        $response["message"] = "Payment details updated successfully";
    } else {
        $response["success"] = false;
        $response["message"] = "Error updating payment: " . mysqli_error($conn);
    }
} else {
    $response["success"] = false;
    $response["message"] = "Invalid request";
}

echo json_encode($response);
mysqli_close($conn);
?>
