<?php
use Google\Auth\Credentials\ServiceAccountCredentials;

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'vendor/autoload.php';
include "db_connect.php";

// ── FCM helpers ───────────────────────────────────────────────────────────────
function getAccessToken() {
    $keyFile = '/home/o96ayd7ennr5/public_html/2025/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    $scopes  = ['https://www.googleapis.com/auth/firebase.messaging'];
    $creds   = new ServiceAccountCredentials($scopes, $keyFile);
    $token   = $creds->fetchAuthToken();
    return $token['access_token'];
}

function sendFcmMessage($projectId, $message) {
    $accessToken = getAccessToken();
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($message));
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
}
// ─────────────────────────────────────────────────────────────────────────────

// Accept both url-encoded POST and raw JSON POST
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : (isset($input['booking_id']) ? intval($input['booking_id']) : 0);
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : (isset($input['reason']) ? trim($input['reason']) : 'Not Specified');

if ($booking_id <= 0) {
    echo json_encode(["status" => "error", "message" => "Invalid booking ID"]);
    exit;
}

// 1. Fetch booking details using prepared statement
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

$non_cancellable = ['Completed', 'Cancelled', 'Customer Cancelled', 'Declined', 'Failed', 'Deleted', 'Cancellation Requested'];
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

// Check if this is a Local Taxi booking
$isLocalTaxi = false;
if (isset($booking['trip_type'])) {
    $tripTypeLower = strtolower($booking['trip_type']);
    if (strpos($tripTypeLower, 'local') !== false && strpos($tripTypeLower, 'taxi') !== false) {
        $isLocalTaxi = true;
    }
}

// 4. Determine calculations and refund percentage
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

// 5. Update booking record
$new_booking_status = "";
$new_refund_status = "";
$new_vendor_comp_status = ($vendor_compensation > 0) ? 'Pending' : null;

if ($isLocalTaxi) {
    $new_booking_status = 'Cancelled';
    $new_refund_status = ((int)$policy['auto_refund'] === 1) ? 'Completed' : 'Processing';
} else {
    if ((int)$policy['manual_approval'] === 1) {
        $new_booking_status = 'Cancellation Requested';
        $new_refund_status = 'Pending Approval';
    } else {
        // Use 'Customer Cancelled' so the driver app can distinguish this from
        // driver-side cancellations and move it to Cancellation History.
        $new_booking_status = 'Customer Cancelled';
        $new_refund_status = ((int)$policy['auto_refund'] === 1) ? 'Completed' : 'Processing';
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

    // ── Notify the assigned driver/vendor via FCM ─────────────────────────────
    try {
        $driver_fcm_token = null;

        // First try to get the vendor's FCM token
        if (!empty($booking['vender_id'])) {
            $vendorStmt = $conn->prepare(
                "SELECT fcm_token FROM drivers WHERE phone_number = ? AND fcm_token IS NOT NULL AND fcm_token != '' LIMIT 1"
            );
            $vendorStmt->bind_param("s", $booking['vender_id']);
            $vendorStmt->execute();
            $vendorResult = $vendorStmt->get_result();
            if ($vendorRow = $vendorResult->fetch_assoc()) {
                $driver_fcm_token = $vendorRow['fcm_token'];
            }
            $vendorStmt->close();
        }

        // Fallback: try driver_id directly
        if (empty($driver_fcm_token) && !empty($booking['driver_id'])) {
            $driverStmt = $conn->prepare(
                "SELECT fcm_token FROM drivers WHERE id = ? AND fcm_token IS NOT NULL AND fcm_token != '' LIMIT 1"
            );
            $driverStmt->bind_param("i", $booking['driver_id']);
            $driverStmt->execute();
            $driverResult = $driverStmt->get_result();
            if ($driverRow = $driverResult->fetch_assoc()) {
                $driver_fcm_token = $driverRow['fcm_token'];
            }
            $driverStmt->close();
        }

        if (!empty($driver_fcm_token)) {
            if ($isLocalTaxi) {
                $notifBody = 'Booking #' . $booking_id . ' has been cancelled by customer.';
            } else {
                $refundText = ($refund_percentage > 0)
                    ? number_format($refund_amount, 2) . ' INR (' . number_format($refund_percentage, 0) . '% refund)'
                    : 'No refund applicable';
                $notifBody = 'Booking #' . $booking_id . ' has been cancelled. '
                           . 'Refund: ' . $refundText . '. '
                           . 'Check Cancellation History for details.';
            }

            $notifMessage = [
                'message' => [
                    'token' => $driver_fcm_token,
                    'notification' => [
                        'title' => '⚠️ Trip Cancelled by Customer',
                        'body'  => $notifBody
                    ],
                    'data' => [
                        'type'               => 'customer_cancelled',
                        'booking_id'         => (string)$booking_id,
                        'booking_status'     => $new_booking_status,
                        'refund_amount'      => (string)round($refund_amount, 2),
                        'refund_percentage'  => (string)$refund_percentage,
                        'cancellation_charge'=> (string)round($cancellation_charge, 2),
                    ]
                ]
            ];

            sendFcmMessage('agni-car-app', $notifMessage);
        }
    } catch (Throwable $fcmEx) {
        // Never let notification errors break the main response
    }
    // ─────────────────────────────────────────────────────────────────────────
} else {
    echo json_encode(["status" => "error", "message" => "Database update failed: " . $conn->error]);
}

$updateStmt->close();
$conn->close();
?>
