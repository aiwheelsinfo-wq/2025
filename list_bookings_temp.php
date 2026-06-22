<?php
include 'db_connect.php';
$result = $conn->query("SELECT id, date, time, paid_amount, total_amount, booking_status FROM bookings ORDER BY id DESC LIMIT 5");
$bookings = [];
while ($row = $result->fetch_assoc()) {
    $bookings[] = $row;
}
echo json_encode($bookings);
$conn->close();
?>
