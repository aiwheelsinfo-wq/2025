<?php
header("Content-Type: application/json");
include 'db_connect.php'; // your DB connection file

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


$response = [];

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['booking_id']) || !isset($data['payment_id']) || !isset($data['status']) || !isset($data['amount'])) {
    $response['success'] = false;
    $response['message'] = "Missing required parameters";
    echo json_encode($response);
    exit;
}

$booking_id = $data['booking_id'];
$payment_id = $data['payment_id'];
$status = $data['status']; // "success", "failed", etc.
$amount = $data['amount'];

// Optional: sanitize input to prevent SQL injection
$booking_id = mysqli_real_escape_string($conn, $booking_id);
$payment_id = mysqli_real_escape_string($conn, $payment_id);
$status = mysqli_real_escape_string($conn, $status);
$amount = mysqli_real_escape_string($conn, $amount);

// Add columns to your `bookings` table if not already there:
// ALTER TABLE bookings ADD payment_id VARCHAR(255), ADD payment_status VARCHAR(100), ADD paid_amount DECIMAL(10,2);

// Fetch booking details
$booking_query = mysqli_query($conn, "SELECT trip_type FROM bookings WHERE id = '$booking_id'");
$booking_info = mysqli_fetch_assoc($booking_query);
$trip_type = $booking_info['trip_type'] ?? '';

if ($status === 'success') {
    if ($trip_type === 'Round-Trip') {
        $payment_status_db = 'Advance Paid';
        $booking_status_db = 'Confirmed';
    } else {
        $payment_status_db = 'success';
        $booking_status_db = 'Pending';
    }
} else {
    $payment_status_db = 'Failed';
    $booking_status_db = 'Failed';
}

$sql = "UPDATE bookings 
        SET payment_id = '$payment_id', 
            payment_status = '$payment_status_db', 
            paid_amount = '$amount', 
            booking_status = '$booking_status_db'
        WHERE id = '$booking_id'";

if (mysqli_query($conn, $sql)) {
    $response['success'] = true;
    $response['message'] = "Payment updated successfully";
} else {
    $response['success'] = false;
    $response['message'] = "Database error: " . mysqli_error($conn);
}

echo json_encode($response);
?>
