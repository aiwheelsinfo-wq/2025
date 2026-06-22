<?php
include "db_connect.php";
$query = "SELECT id, date, time, total_amount, paid_amount, booking_status FROM bookings ORDER BY id DESC LIMIT 5";
$result = $conn->query($query);
$bookings = [];
while ($row = $result->fetch_assoc()) {
    $pickup_str = $row['date'] . ' ' . $row['time'];
    $pickup_ts = strtotime($pickup_str);
    $current_ts = time();
    $diff_seconds = $pickup_ts - $current_ts;
    $diff_hours = $diff_seconds / 3600.0;
    $row['pickup_str'] = $pickup_str;
    $row['pickup_ts'] = $pickup_ts;
    $row['current_ts'] = $current_ts;
    $row['current_date_time'] = date('Y-m-d H:i:s', $current_ts);
    $row['diff_hours'] = $diff_hours;
    $bookings[] = $row;
}
echo json_encode($bookings, JSON_PRETTY_PRINT);
?>
