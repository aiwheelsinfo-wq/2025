<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include "db_connect.php";

// Accept both url-encoded POST and raw JSON POST
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : (isset($input['booking_id']) ? intval($input['booking_id']) : 0);
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : (isset($input['reason']) ? trim($input['reason']) : 'Not Specified');

if ($booking_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid booking ID"]);
    exit;
}

// 1. Fetch booking details using prepared statement
$stmt = $conn->prepare("SELECT id, date, time, total_amount, paid_amount, payment_type, booking_status, vender_id, driver_id FROM bookings WHERE id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(["status" => "error", "message" => "Booking not found"]);
    exit;
}

$non_cancellable = ['Completed', 'Cancelled', 'Declined', 'Failed', 'Deleted', 'Cancellation Requested'];
if (in_array($booking['booking_status'], $non_cancellable)) {
    echo json_encode([
        "status" => "error", 
        "message" => "Booking is already in " . $booking['booking_status'] . " status and cannot be cancelled"
    ]);
    exit;
}

// 2. Fetch latest cancellation policy
$policyResult = $conn->query("SELECT * FROM cancellation_policy ORDER BY id DESC LIMIT 1");
$policy = $policyResult ? $policyResult->fetch_assoc() : null;

if (!$policy) {
    echo json_encode(["status" => "error", "message" => "Cancellation policy is not configured"]);
    exit;
}

if ((int)$policy['cancellation_enabled'] === 0) {
    echo json_encode(["status" => "error", "message" => "Booking cancellation is currently disabled by administrator"]);
    exit;
}

// 3. Verify pickup time
$pickup_str = $booking['date'] . ' ' . $booking['time'];
$pickup_ts = strtotime($pickup_str);
$current_ts = time(); // Server time in Asia/Kolkata
$diff_seconds = $pickup_ts - $current_ts;
$diff_hours = $diff_seconds / 3600.0;

if ($diff_hours <= 0) {
    echo json_encode(["status" => "error", "message" => "Trip has already started. Cancellation is not allowed."]);
    exit;
}

// 4. Determine refund percentage based on pickup hours
$refund_percentage = 0.00;
if ($diff_hours >= 48) {
    $refund_percentage = (float)$policy['refund_above_48h'];
} elseif ($diff_hours >= 24) {
    $refund_percentage = (float)$policy['refund_24_48h'];
} elseif ($diff_hours >= 12) {
    $refund_percentage = (float)$policy['refund_12_24h'];
} elseif ($diff_hours >= 6) {
    $refund_percentage = (float)$policy['refund_6_12h'];
} else {
    $refund_percentage = (float)$policy['refund_below_6h'];
}

// Calculate values based on advance paid
$trip_amount = (float)$booking['total_amount'];
$advance_paid = (float)$booking['paid_amount'];

$refund_amount = $advance_paid * ($refund_percentage / 100.0);
$cancellation_charge = $advance_paid - $refund_amount;

// Calculate vendor compensation (vendor protection)
$vendor_compensation = 0.00;
if (!empty($booking['vender_id']) || !empty($booking['driver_id'])) {
    $vendor_comp_percent = 0.00;
    if ($diff_hours >= 24) {
        $vendor_comp_percent = (float)$policy['vendor_comp_above_24h'];
    } elseif ($diff_hours >= 6) {
        $vendor_comp_percent = (float)$policy['vendor_comp_6_24h'];
    } else {
        $vendor_comp_percent = (float)$policy['vendor_comp_below_6h'];
    }
    // Compensation is a percentage of the cancellation charge
    $vendor_compensation = $cancellation_charge * ($vendor_comp_percent / 100.0);
}

// 5. Update booking record
$new_booking_status = "";
$new_refund_status = "";
$new_vendor_comp_status = ($vendor_compensation > 0) ? 'Pending' : null;

if ((int)$policy['manual_approval'] === 1) {
    $new_booking_status = 'Cancellation Requested';
    $new_refund_status = 'Pending Approval';
} else {
    $new_booking_status = 'Cancelled';
    if ((int)$policy['auto_refund'] === 1) {
        $new_refund_status = 'Completed';
    } else {
        $new_refund_status = 'Processing';
    }
}

$updateStmt = $conn->prepare("UPDATE bookings SET 
    booking_status = ?, 
    cancellation_reason = ?, 
    cancelled_at = NOW(), 
    cancellation_charge = ?, 
    refund_amount = ?, 
    refund_status = ?, 
    vendor_compensation = ?, 
    vendor_compensation_status = ? 
    WHERE id = ?");

$updateStmt->bind_param("ssddsdsi", 
    $new_booking_status, 
    $reason, 
    $cancellation_charge, 
    $refund_amount, 
    $new_refund_status, 
    $vendor_compensation, 
    $new_vendor_comp_status, 
    $booking_id
);

if ($updateStmt->execute()) {
    echo json_encode([
        "status" => "success",
        "message" => "Booking cancellation request processed successfully",
        "data" => [
            "booking_id" => $booking_id,
            "booking_status" => $new_booking_status,
            "refund_status" => $new_refund_status,
            "refund_amount" => round($refund_amount, 2),
            "cancellation_charge" => round($cancellation_charge, 2),
            "vendor_compensation" => round($vendor_compensation, 2)
        ]
    ]);
} else {
    echo json_encode(["status" => "error", "message" => "Database update failed: " . $conn->error]);
}

$updateStmt->close();
$conn->close();
?>
