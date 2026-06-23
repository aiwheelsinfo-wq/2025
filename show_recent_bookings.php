<?php
header("Content-Type: application/json");
include 'db_connect.php';

$res = [];

// 1. Fetch latest 5 bookings
$q1 = mysqli_query($conn, "SELECT * FROM bookings ORDER BY id DESC LIMIT 5");
$res['bookings'] = [];
if ($q1) {
    while ($row = mysqli_fetch_assoc($q1)) {
        $res['bookings'][] = $row;
    }
}

// 2. Fetch latest 5 partner_bookings
$q2 = mysqli_query($conn, "SELECT * FROM partner_bookings ORDER BY id DESC LIMIT 5");
$res['partner_bookings'] = [];
if ($q2) {
    while ($row = mysqli_fetch_assoc($q2)) {
        $res['partner_bookings'][] = $row;
    }
}

// 3. Fetch latest 5 ed_passenger_booking
$q3 = mysqli_query($conn, "SELECT * FROM ed_passenger_booking ORDER BY id DESC LIMIT 5");
$res['ed_passenger_booking'] = [];
if ($q3) {
    while ($row = mysqli_fetch_assoc($q3)) {
        $res['ed_passenger_booking'][] = $row;
    }
}

echo json_encode($res);
mysqli_close($conn);
?>
