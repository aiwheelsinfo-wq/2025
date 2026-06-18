<?php
include 'db_connect.php';
header('Content-Type: application/json');

$response = [];

// 1. Show bookings table schema
$result = $conn->query("DESCRIBE bookings");
if ($result) {
    $response['bookings_columns'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['bookings_columns'][] = $row['Field'] . ' (' . $row['Type'] . ')';
    }
} else {
    $response['bookings_columns_error'] = $conn->error;
}

// 2. Show users table schema
$result = $conn->query("DESCRIBE users");
if ($result) {
    $response['users_columns'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['users_columns'][] = $row['Field'] . ' (' . $row['Type'] . ')';
    }
} else {
    $response['users_columns_error'] = $conn->error;
}

// 3. Show latest 5 bookings
$result = $conn->query("SELECT id, mobile, booker_id, trip_type, car_type, total_amount, booking_status FROM bookings ORDER BY id DESC LIMIT 5");
if ($result) {
    $response['latest_bookings'] = [];
    while ($row = $result->fetch_assoc()) {
        $response['latest_bookings'][] = $row;
    }
} else {
    $response['latest_bookings_error'] = $conn->error;
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>
