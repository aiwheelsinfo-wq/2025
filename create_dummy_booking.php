<?php
header("Content-Type: application/json");
include 'db_connect.php';

// Insert dummy booking
$sql_insert = "INSERT INTO bookings (
    trip_type, car_type, from_address, to_address, date, time, 
    total_amount, vendor_amount, agni_amount, paid_amount, 
    base_charge, booking_status, vender_id, mobile
) VALUES (
    'One-way', 'Sedan', 'Pickup Location Test', 'Drop Location Test', 
    '2026-06-24', '12:00:00', 1500.00, 1200.00, 300.00, 0.00, 
    1000.00, 'Pending', '', '1234567890'
)";

if (!mysqli_query($conn, $sql_insert)) {
    echo json_encode(["success" => false, "error" => mysqli_error($conn)]);
    mysqli_close($conn);
    exit;
}

$booking_id = mysqli_insert_id($conn);

// Query using get_booking_details.php logic
$sql_query = "SELECT 
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

$result = mysqli_query($conn, $sql_query);
$data = null;
if ($result && mysqli_num_rows($result) > 0) {
    $data = mysqli_fetch_assoc($result);
}

// Clean up: delete the dummy booking so we don't pollute the database
mysqli_query($conn, "DELETE FROM bookings WHERE id = '$booking_id'");

echo json_encode([
    "success" => true,
    "inserted_id" => $booking_id,
    "fetched_data" => $data
]);

mysqli_close($conn);
?>
