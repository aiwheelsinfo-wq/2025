<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include "db_connect.php";

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if ($booking_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid booking ID"]);
    exit;
}

// Fetch booking details using prepared statement to prevent SQL Injection
$stmt = $conn->prepare("SELECT id, trip_type, date, time, total_amount, paid_amount, payment_type, booking_status, vender_id, driver_id FROM bookings WHERE id = ?");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$result = $stmt->get_result();
$booking = $result->fetch_assoc();
$stmt->close();

if (!$booking) {
    echo json_encode(["status" => "error", "message" => "Booking not found"]);
    exit;
}

$non_cancellable = ['Completed', 'Cancelled', 'Declined', 'Failed', 'Deleted', 'Cancellation Requested', 'Started', 'Ongoing'];
if (in_array($booking['booking_status'], $non_cancellable)) {
    echo json_encode([
        "status" => "error", 
        "message" => "Booking is already in " . $booking['booking_status'] . " status and cannot be cancelled"
    ]);
    exit;
}

// Fetch latest cancellation policy
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

// Check if this is a Local Taxi booking
$isLocalTaxi = false;
if (isset($booking['trip_type'])) {
    $tripTypeLower = strtolower($booking['trip_type']);
    if (strpos($tripTypeLower, 'local') !== false && strpos($tripTypeLower, 'taxi') !== false) {
        $isLocalTaxi = true;
    }
}

// Calculate time remaining before pickup
if (!$isLocalTaxi) {
    $pickup_str = $booking['date'] . ' ' . $booking['time'];
    $pickup_ts = strtotime($pickup_str);
    $current_ts = time(); // Server time in Asia/Kolkata
    $diff_seconds = $pickup_ts - $current_ts;
    $diff_hours = $diff_seconds / 3600.0;

    if ($diff_hours <= 0) {
        echo json_encode(["status" => "error", "message" => "Trip has already started or is in the past. Cancellation is not allowed."]);
        exit;
    }
} else {
    $diff_hours = 0.0;
}

// Determine calculations and refund percentage
$refund_percentage = 0.00;
$trip_amount = (float)$booking['total_amount'];
$advance_paid = (float)$booking['paid_amount'];
$vendor_compensation = 0.00;

if ($isLocalTaxi) {
    // Local Taxi rules: free cancellation, 100% refund, 0 charges, 0 vendor compensation
    $refund_percentage = 100.00;
    $refund_amount = $advance_paid;
    $cancellation_charge = 0.00;
} else {
    // Standard calculation based on pickup hours
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

    $refund_amount = $advance_paid * ($refund_percentage / 100.0);
    $cancellation_charge = $advance_paid - $refund_amount;

    // Calculate vendor compensation (vendor protection)
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
}

echo json_encode([
    "status" => "success",
    "data" => [
        "booking_id" => $booking['id'],
        "trip_amount" => $trip_amount,
        "advance_paid" => $advance_paid,
        "hours_before_pickup" => round($diff_hours, 2),
        "refund_percentage" => $refund_percentage,
        "cancellation_charge" => round($cancellation_charge, 2),
        "refund_amount" => round($refund_amount, 2),
        "vendor_compensation" => round($vendor_compensation, 2),
        "manual_approval" => (int)$policy['manual_approval'],
        "auto_refund" => (int)$policy['auto_refund']
    ]
]);

$conn->close();
?>
