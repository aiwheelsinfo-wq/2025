<?php
include "db_connect.php";
$stmt = $conn->prepare("UPDATE bookings SET date = '2026-06-22', time = '18:30:00', booking_status = 'Pending' WHERE id = 2012");
if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Booking 2012 updated to today at 18:30:00"]);
} else {
    echo json_encode(["status" => "error", "message" => $conn->error]);
}
$stmt->close();
$conn->close();
?>
