<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db_connect.php';
date_default_timezone_set('Asia/Kolkata');

// Decode JSON or POST input
$input = file_get_contents("php://input");
$data = json_decode($input, true);
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

$trip_id      = isset($data['trip_id']) ? intval($data['trip_id']) : 0;
$starting_km  = isset($data['starting_km']) ? intval($data['starting_km']) : null;
$start_lat    = isset($data['start_lat']) ? floatval($data['start_lat']) : null;
$start_lng    = isset($data['start_lng']) ? floatval($data['start_lng']) : null;
$starting_time = date("H:i:s");
$current_date  = date('Y-m-d');

// Log request for debugging
@file_put_contents("debug_log.txt", json_encode($data) . PHP_EOL, FILE_APPEND);

if ($trip_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Valid trip_id is required."
    ]);
    exit;
}

// Check existing booking to get or generate end_otp and keep start otp intact
$existingQ = $conn->query("SELECT otp, end_otp, trip_type FROM bookings WHERE id = '$trip_id' LIMIT 1");
$existingEndOtp = '';
$startOtp = '';
$tripType = '';
if ($existingQ && $exRow = $existingQ->fetch_assoc()) {
    $existingEndOtp = trim($exRow['end_otp'] ?? '');
    $startOtp = trim($exRow['otp'] ?? '');
    $tripType = trim($exRow['trip_type'] ?? '');
}

// Generate secure 4-digit End OTP for drop-off completion if not already present
$end_otp = (!empty($existingEndOtp) && strlen($existingEndOtp) >= 4) ? $existingEndOtp : strval(rand(1000, 9999));

// Build dynamic update
$sql = "UPDATE bookings 
        SET booking_status = 'In-Transit', 
            starting_time = ?, 
            starting_date = ?, 
            gps_start_time = NOW(),
            gps_accumulated_km = 0.00,
            end_otp = ?";

$params = [$starting_time, $current_date, $end_otp];
$types  = "sss";

if ($starting_km !== null) {
    $sql .= ", starting_km = ?";
    $params[] = $starting_km;
    $types .= "i";
}

if ($start_lat !== null && $start_lng !== null) {
    $sql .= ", gps_start_lat = ?, gps_start_lng = ?";
    $params[] = $start_lat;
    $params[] = $start_lng;
    $types .= "dd";
}

$sql .= " WHERE id = ?";
$params[] = $trip_id;
$types .= "i";

$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $result = $stmt->execute();
    $stmt->close();

    if ($result) {
        echo json_encode([
            "success" => true,
            "message" => "Trip started successfully.",
            "trip_id" => $trip_id,
            "start_otp" => $startOtp,
            "end_otp" => $end_otp,
            "status" => "In-Transit"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to update trip status: " . $conn->error
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => "SQL prepare failed: " . $conn->error
    ]);
}

$conn->close();
?>
