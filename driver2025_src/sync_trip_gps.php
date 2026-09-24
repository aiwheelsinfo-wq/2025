<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db_connect.php';
date_default_timezone_set('Asia/Kolkata');

$input = file_get_contents("php://input");
$data = json_decode($input, true);
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

$booking_id = isset($data['booking_id']) ? intval($data['booking_id']) : 0;
$driver_id  = isset($data['driver_id']) ? trim($data['driver_id']) : '';
$current_km = isset($data['current_km']) ? floatval($data['current_km']) : 0.0;
$lat        = isset($data['lat']) ? floatval($data['lat']) : null;
$lng        = isset($data['lng']) ? floatval($data['lng']) : null;
$speed      = isset($data['speed']) ? floatval($data['speed']) : 0.0;
$accuracy   = isset($data['accuracy']) ? floatval($data['accuracy']) : null;

if ($booking_id <= 0) {
    echo json_encode([
        "success" => false,
        "message" => "Valid booking_id is required."
    ]);
    exit;
}

// 1. Update accumulated distance in bookings table (monotonic - never decrease)
$updateBookingsSql = "UPDATE bookings 
                      SET gps_accumulated_km = GREATEST(COALESCE(gps_accumulated_km, 0), ?) 
                      WHERE id = ?";
$stmt = $conn->prepare($updateBookingsSql);
if ($stmt) {
    $stmt->bind_param("di", $current_km, $booking_id);
    $stmt->execute();
    $stmt->close();
}

// 2. Update driver's current position if lat/lng provided
if ($lat !== null && $lng !== null && !empty($driver_id)) {
    $safePhone = mysqli_real_escape_string($conn, $driver_id);
    $conn->query("UPDATE drivers SET latitude = '$lat', longitude = '$lng' WHERE phone_number = '$safePhone'");
}

// 3. Insert coordinate breadcrumb into trip_gps_breadcrumbs for auditable trail
if ($lat !== null && $lng !== null && abs($lat) > 0.0001 && abs($lng) > 0.0001) {
    $breadSql = "INSERT INTO trip_gps_breadcrumbs (booking_id, latitude, longitude, speed_kmh, accuracy_meters) VALUES (?, ?, ?, ?, ?)";
    $bStmt = $conn->prepare($breadSql);
    if ($bStmt) {
        $bStmt->bind_param("idddd", $booking_id, $lat, $lng, $speed, $accuracy);
        $bStmt->execute();
        $bStmt->close();
    }
}

// 4. Return current synced state
$fetchStmt = $conn->prepare("SELECT gps_accumulated_km, end_otp, booking_status FROM bookings WHERE id = ?");
$savedKm = $current_km;
$endOtp = '';
$status = '';
if ($fetchStmt) {
    $fetchStmt->bind_param("i", $booking_id);
    $fetchStmt->execute();
    $res = $fetchStmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $savedKm = floatval($row['gps_accumulated_km'] ?? $current_km);
        $endOtp  = strval($row['end_otp'] ?? '');
        $status  = strval($row['booking_status'] ?? '');
    }
    $fetchStmt->close();
}

echo json_encode([
    "success" => true,
    "booking_id" => $booking_id,
    "gps_accumulated_km" => $savedKm,
    "end_otp" => $endOtp,
    "booking_status" => $status,
    "timestamp" => date('Y-m-d H:i:s')
]);

$conn->close();
?>
