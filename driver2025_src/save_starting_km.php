<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

error_reporting(E_ALL);
ini_set('display_errors', 0);

include 'db_connect.php';
date_default_timezone_set('Asia/Kolkata');

/**
 * UltraMsg WhatsApp Notification Helper
 */
if (!function_exists('sendUltraMsgWhatsApp')) {
    function sendUltraMsgWhatsApp($phone, $message) {
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleanPhone) === 10) {
            $cleanPhone = '91' . $cleanPhone;
        }
        if (strlen($cleanPhone) < 10) {
            return false;
        }

        $url = "https://api.ultramsg.com/instance182608/messages/chat";
        $payload = [
            'token' => 'h4ltyv2brjcj63jz',
            'to' => $cleanPhone,
            'body' => $message,
            'priority' => 10
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }
}

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
$existingQ = $conn->query("SELECT id, otp, end_otp, trip_type, mobile, customer_number, car_type, vehicle_id, driver_id, from_address, to_address FROM bookings WHERE id = '$trip_id' LIMIT 1");
$existingEndOtp = '';
$startOtp = '';
$tripType = '';
$existingBookingRow = null;
if ($existingQ && $exRow = $existingQ->fetch_assoc()) {
    $existingEndOtp = trim($exRow['end_otp'] ?? '');
    $startOtp = trim($exRow['otp'] ?? '');
    $tripType = trim($exRow['trip_type'] ?? '');
    $existingBookingRow = $exRow;
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
        // Send WhatsApp notification for Round-Trip starting KM to customer
        try {
            $isRoundTrip = (strcasecmp($tripType, 'Round-Trip') === 0 || strcasecmp($tripType, 'Round trip') === 0 || strcasecmp($tripType, 'Round-trip') === 0);
            if ($isRoundTrip && !empty($existingBookingRow)) {
                $custPhone = !empty($existingBookingRow['mobile']) ? trim($existingBookingRow['mobile']) : trim($existingBookingRow['customer_number'] ?? '');
                if (!empty($custPhone)) {
                    // Get customer name if available
                    $custName = 'Customer';
                    $cleanCustPhone = mysqli_real_escape_string($conn, $custPhone);
                    $cQ = $conn->query("SELECT name FROM customers WHERE mobile = '$cleanCustPhone' LIMIT 1");
                    if ($cQ && $cRow = $cQ->fetch_assoc()) {
                        if (!empty($cRow['name'])) $custName = trim($cRow['name']);
                    }

                    // Get driver name if available
                    $driverName = 'Assigned Driver';
                    $driverId = trim($existingBookingRow['driver_id'] ?? '');
                    if (!empty($driverId)) {
                        $cleanDId = mysqli_real_escape_string($conn, $driverId);
                        $dQ = $conn->query("SELECT full_name FROM drivers WHERE phone_number = '$cleanDId' LIMIT 1");
                        if ($dQ && $dRow = $dQ->fetch_assoc()) {
                            if (!empty($dRow['full_name'])) $driverName = trim($dRow['full_name']);
                        }
                    }

                    $carType = !empty($existingBookingRow['car_type']) ? trim($existingBookingRow['car_type']) : 'Cab';
                    $vehId = !empty($existingBookingRow['vehicle_id']) ? " (" . trim($existingBookingRow['vehicle_id']) . ")" : '';
                    $kmFormatted = ($starting_km !== null) ? number_format($starting_km) . " KM" : "Recorded";
                    $fromParts = !empty($existingBookingRow['from_address']) ? explode(',', $existingBookingRow['from_address']) : ['Pickup'];
                    $fromLoc = trim($fromParts[0]);
                    $toParts = !empty($existingBookingRow['to_address']) ? explode(',', $existingBookingRow['to_address']) : ['Destination'];
                    $toLoc = trim($toParts[0]);

                    $msg = "🚗 *Trip Started - Agni Car Rental*\n\n"
                         . "Hello *" . $custName . "*,\n"
                         . "Your Round-Trip booking (*#" . $trip_id . "*) has officially started.\n\n"
                         . "📍 *Starting Odometer:* *" . $kmFormatted . "*\n"
                         . "⏰ *Start Time:* " . date("h:i A", strtotime($starting_time)) . "\n"
                         . "🚘 *Vehicle:* " . $carType . $vehId . "\n"
                         . "👨‍✈️ *Driver:* " . $driverName . "\n"
                         . "🛣️ *Route:* " . $fromLoc . " ➔ " . $toLoc . "\n\n"
                         . "🔐 *End OTP for Drop-Off:* *" . $end_otp . "*\n"
                         . "_(Please share this OTP with driver only when your trip is completed)_\n\n"
                         . "Have a safe and pleasant journey with Agni Car Rental!";

                    sendUltraMsgWhatsApp($custPhone, $msg);
                }
            }
        } catch (Throwable $notifErr) {
            // Fail-safe: Notification issue must NEVER break trip start response
            @file_put_contents("whatsapp_log.txt", "Start Trip Err: " . $notifErr->getMessage() . PHP_EOL, FILE_APPEND);
        }

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
