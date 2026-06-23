<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata'); // or your preferred timezone

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';
$otp = rand(1000, 9999);

if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON input"]);
        exit;
    }

    // Validate required fields
    if (
        empty($data['from_address']) ||
        empty($data['to_address']) ||
        empty($data['car_type']) ||
        empty($data['distance']) ||
        !isset($data['total_amount']) ||
        empty($data['phone_number'])
    ) {
        echo json_encode(["status" => "error", "message" => "Missing required fields"]);
        exit;
    }

    // Extract data
    $from_address = $data['from_address'];
    $to_address = $data['to_address'];
    $car_type = $data['car_type'];
    $distance = floatval($data['distance']);
    $total_amount = floatval($data['total_amount']);
    $phone_number = $data['phone_number'];

    // Validate phone number
    if (!preg_match('/^\d{10}$/', $phone_number)) {
        echo json_encode(["status" => "error", "message" => "Invalid phone number"]);
        exit;
    }

    // Current date and time
    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');

    // Local Taxi: Vendor receives 100% — no Agni commission or platform fee deducted.
    $agni_amount = 0;
    $vendor_amount = $total_amount;

    // Prepare SQL statement
    $sql = "INSERT INTO bookings (from_address, to_address, distance, car_type, total_amount, trip_type, date, time, mobile, vendor_amount, agni_amount, otp) 
            VALUES (?, ?, ?, ?, ?, 'Local-taxi', ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(["status" => "error", "message" => "Query preparation failed"]);
        exit;
    }

    $stmt->bind_param(
        'ssdsdssddss',
        $from_address,
        $to_address,
        $distance,
        $car_type,
        $total_amount,
        $current_date,
        $current_time,
        $phone_number,
        $vendor_amount,
        $agni_amount,
        $otp
    );

    if ($stmt->execute()) {
        $booking_id = $conn->insert_id;

        try {
            require_once __DIR__ . '/../send_new_booking_notification.php';
            trigger_new_booking_notification($booking_id);
        } catch (Throwable $e) {
            error_log("FCM Notification error: " . $e->getMessage());
        }

        echo json_encode([
            "status" => "success",
            "message" => "Booking created successfully",
            "id" => $booking_id
        ]);
    } else {
        echo json_encode(["status" => "error", "message" => "Database error: " . $stmt->error]);
    }

    $stmt->close();
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Server error: " . $e->getMessage()]);
}

$conn->close();
?>