<?php
header("Content-Type: application/json");
include 'db_connect.php';

$booking_id = $_GET['booking_id'] ?? $_POST['booking_id'] ?? null;

if (!$booking_id) {
    echo json_encode(["success" => false, "message" => "booking_id is required"]);
    exit;
}

$booking_id = mysqli_real_escape_string($conn, $booking_id);

$sql = "SELECT 
            b.id,
            b.trip_type,
            b.car_type,
            b.from_address,
            b.to_address,
            b.date,
            b.time,
            b.total_amount,
            b.vendor_amount,
            b.agni_amount,
            b.paid_amount,
            b.base_charge,
            b.booking_status,
            b.vender_id,
            u.name AS customer_name
        FROM bookings b
        LEFT JOIN users u ON b.mobile = u.phone_number
        WHERE b.id = '$booking_id'";

$result = mysqli_query($conn, $sql);

if ($result && mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    // Format date and time
    if (!empty($row['date'])) {
        $row['formatted_date'] = date('d M, Y', strtotime($row['date']));
    } else {
        $row['formatted_date'] = '';
    }
    if (!empty($row['time'])) {
        $row['formatted_time'] = date('h:i A', strtotime($row['time']));
    } else {
        $row['formatted_time'] = '';
    }
    echo json_encode([
        "success" => true,
        "data" => $row
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Booking not found"
    ]);
}

mysqli_close($conn);
?>
