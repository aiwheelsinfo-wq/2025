<?php
// check_booking.php
header('Content-Type: application/json; charset=utf-8');

require_once 'db_connect.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection not available']);
    exit;
}

if (!isset($_GET['booker_id']) || trim($_GET['booker_id']) === '') {
    http_response_code(400);
    echo json_encode(['error' => 'booker_id is required']);
    exit;
}

$booker_id = $_GET['booker_id'];

try {
    // Fetch all bookings for this user ordered by booking date/time
    $sql = "SELECT id, date, time FROM bookings WHERE booker_id = ? ORDER BY date ASC, time ASC";
    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        throw new Exception('Preparation failed: ' . $conn->error);
    }

    $stmt->bind_param('s', $booker_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result === false) {
        throw new Exception('Get result failed: ' . $stmt->error);
    }

    $bookings = [];
    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $count++;
        // Only first 5 trips get discount
        $row['discount_percent'] = ($count <= 5) ? 10 : 0;
        $bookings[] = $row;
    }

    echo json_encode([
        'success' => true,
        'total_bookings' => $count,
        'bookings' => $bookings
    ]);

    $stmt->close();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}
?>
