<?php
include '../2025/db_connect.php'; // Include database connection

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $booking_id = $_POST['booking_id'] ?? '';

    if (!empty($booking_id)) {
        // Send WhatsApp cancellation notification to customer first (while we can still retrieve mobile number easily)
        try {
            if (file_exists(__DIR__ . '/../notification_helper.php')) {
                require_once __DIR__ . '/../notification_helper.php';
            } else {
                require_once __DIR__ . '/../2025/notification_helper.php';
            }
            sendCancelWhatsAppNotification($booking_id, $conn);
        } catch (Throwable $e) {
            error_log("WhatsApp Cancel Notification error: " . $e->getMessage());
        }

        $sql = "UPDATE bookings SET booking_status = 'Pending', driver_id = '', vehicle_id = '', vender_id='' WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $booking_id);

        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Booking cancelled successfully"]);
        } else {
            echo json_encode(["success" => false, "message" => "Error cancelling booking"]);
        }
        $stmt->close();
    } else {
        echo json_encode(["success" => false, "message" => "Invalid booking ID"]);
    }
}
?>
