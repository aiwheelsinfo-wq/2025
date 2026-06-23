<?php
header('Content-Type: application/json');
include '../2025/db_connect.php'; 

$booking_id = $_POST['booking_id'] ?? '';
$driver_id = $_POST['driver_id'] ?? ''; // This is the vendor's phone number

// Log received parameters
error_log("Received Booking ID: " . $booking_id);
error_log("Received Driver ID: " . $driver_id);

if (empty($booking_id) || empty($driver_id)) {
    echo json_encode(["success" => false, "message" => "Missing required parameters"]);
    exit;
}

// Start transaction
$conn->begin_transaction();

try {
    // Select booking with FOR UPDATE to lock the row
    $stmt = $conn->prepare("SELECT booking_status FROM bookings WHERE id = ? FOR UPDATE");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Booking not found."]);
        exit;
    }
    
    $booking = $result->fetch_assoc();
    $status = $booking['booking_status'];
    
    if ($status !== 'Pending') {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "This booking has already been accepted by another vendor."]);
        exit;
    }
    
    // Update booking status, driver_id, and vender_id
    $updateStmt = $conn->prepare("UPDATE bookings SET driver_id = ?, vender_id = ?, booking_status = 'Accepted' WHERE id = ?");
    $updateStmt->bind_param("ssi", $driver_id, $driver_id, $booking_id);
    
    if ($updateStmt->execute()) {
        $conn->commit();
        echo json_encode(["success" => true, "message" => "Booking accepted successfully"]);
    } else {
        $conn->rollback();
        echo json_encode(["success" => false, "message" => "Failed to accept booking"]);
    }
    
    $updateStmt->close();
    $stmt->close();
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Database error: " . $e->getMessage()]);
}

$conn->close();
?>
