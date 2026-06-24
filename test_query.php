<?php
include 'db_connect.php';

header("Content-Type: application/json");

try {
    $sql = "SELECT id, trip_type, car_type, from_address, to_address, distance, date, time, mobile, booking_status, vendor_amount, booker_id FROM bookings ORDER BY id DESC LIMIT 10";
    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(["error" => $e->getMessage()]);
}
?>
