<?php
include 'db_connect.php';
session_start(); // Include your existing database connection

// Get phone number from POST
$phone_number = $_POST['phone_number'] ?? '';

if (empty($phone_number)) {
    echo json_encode(["success" => false, "message" => "Phone number required"]);
    exit;
}

// ✅ Save phone number in a cookie (valid for 1 day)
$_SESSION['phone_number'] = $phone_number;
// Must be set before any echo/output

// Step 1: Check if user exists and is not blocked
$sql = "SELECT * FROM users WHERE phone_number = ? AND status != 'Blocked'";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    echo json_encode(["success" => true]);
} else {
    echo json_encode(["success" => false]);
}

$stmt->close();

// Step 2: Check number of 'temp' bookings in last 60 days
$checkBookings = $conn->prepare("
    SELECT COUNT(*) AS temp_count 
    FROM bookings 
    WHERE booker_id = ? 
      AND booking_status = 'temp' 
      AND booked_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
");
$checkBookings->bind_param("s", $phone_number);
$checkBookings->execute();
$bookingResult = $checkBookings->get_result();
$bookingRow = $bookingResult->fetch_assoc();

$tempCount = (int)$bookingRow['temp_count'];

// Step 3: Block user if temp bookings exceed 5
if ($tempCount > 5) {
    $updateStatus = $conn->prepare("UPDATE users SET status = 'Blocked' WHERE phone_number = ?");
    $updateStatus->bind_param("s", $phone_number);
    $updateStatus->execute();
    $updateStatus->close();
}

$conn->close();
?>
