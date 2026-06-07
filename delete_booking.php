<?php
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json');

include 'db_connect.php'; // Make sure this connects to your DB

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? '';

    if (!empty($id)) {
        $stmt = $conn->prepare("UPDATE bookings SET booking_status = 'Deleted' WHERE id = ?");
        $stmt->bind_param("i", $id);

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Booking marked as deleted."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update booking status."]);
        }

        $stmt->close();
    } else {
        echo json_encode(["status" => "error", "message" => "Invalid booking ID."]);
    }

    $conn->close();
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>
