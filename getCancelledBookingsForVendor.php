<?php
/**
 * getCancelledBookingsForVendor.php
 * 
 * Returns bookings with status 'Customer Cancelled' or 'Cancellation Requested'
 * for a given vendor/driver (by phone_number).
 */
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include "db_connect.php";

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$phone_number = isset($_POST['phone_number'])
    ? trim($_POST['phone_number'])
    : (isset($input['phone_number']) ? trim($input['phone_number']) : '');

if (empty($phone_number)) {
    echo json_encode(["success" => false, "message" => "phone_number is required"]);
    exit;
}

// Fetch Customer Cancelled or Cancellation Requested bookings for this vendor
$stmt = $conn->prepare("
    SELECT
        b.id             AS booking_id,
        b.trip_type,
        b.from_address   AS pickup_location,
        b.to_address     AS drop_location,
        b.date,
        b.time,
        b.car_type,
        b.booking_status,
        b.cancellation_reason,
        b.refund_amount,
        b.cancellation_charge,
        b.refund_status,
        b.cancelled_at,
        b.total_amount,
        b.paid_amount,
        CONCAT(u.name)   AS customer_name,
        b.mobile         AS customer_contact,
        b.vehicle_id,
        b.driver_id
    FROM bookings b
    LEFT JOIN users u ON u.phone_number = b.mobile
    WHERE b.vender_id = ?
      AND b.booking_status IN ('Customer Cancelled', 'Cancellation Requested', 'Cancelled')
    ORDER BY b.cancelled_at DESC
    LIMIT 50
");

$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

$cancelled = [];
while ($row = $result->fetch_assoc()) {
    // Format time to 12-hour for display
    if (!empty($row['time'])) {
        $row['time'] = date('h:i A', strtotime($row['time']));
    }
    $cancelled[] = $row;
}
$stmt->close();
$conn->close();

echo json_encode([
    "success"           => true,
    "cancelledBookings" => $cancelled,
    "count"             => count($cancelled)
]);
?>
