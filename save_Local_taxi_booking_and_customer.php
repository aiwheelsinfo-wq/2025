<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
date_default_timezone_set('Asia/Kolkata');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include 'db_connect.php';

$otp = rand(1000, 9999);

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit;
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data)) {
        $data = $_POST;
    }
    if (!is_array($data) || empty($data)) {
        echo json_encode(["status" => "error", "message" => "No booking data received."]);
        exit;
    }

    // ✅ Required fields
    $required_fields = [
        'phone_number', 'name', 'email', 'city',
        'pincode', 'from_address', 'to_address', 'car_type', 'total_amount', 'distance'
    ];

    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || strlen(trim((string)$data[$field])) === 0) {
            echo json_encode(["status" => "error", "message" => "Missing or empty required field: $field"]);
            exit;
        }
    }

    // ✅ Sanitize input
    $phone_number   = $conn->real_escape_string($data['phone_number']);
    $booking_number = !empty($data['booking_number']) ? $conn->real_escape_string($data['booking_number']) : $phone_number;
    $name           = $conn->real_escape_string($data['name']);
    $email          = $conn->real_escape_string($data['email']);
    $city           = $conn->real_escape_string($data['city']);
    $pincode        = $conn->real_escape_string($data['pincode']);
    $from_address   = $conn->real_escape_string($data['from_address']);
    $to_address     = $conn->real_escape_string($data['to_address']);
    $car_type       = $conn->real_escape_string($data['car_type']);
    $total_amount   = floatval($data['total_amount']);
    $distance       = floatval($data['distance']);

    $from_lat = (isset($data['from_lat']) && !empty(trim($data['from_lat']))) ? floatval($data['from_lat']) : null;
    $from_lng = (isset($data['from_lng']) && !empty(trim($data['from_lng']))) ? floatval($data['from_lng']) : null;
    $to_lat   = (isset($data['to_lat']) && !empty(trim($data['to_lat']))) ? floatval($data['to_lat']) : null;
    $to_lng   = (isset($data['to_lng']) && !empty(trim($data['to_lng']))) ? floatval($data['to_lng']) : null;

    // ✅ Geocode function with short timeout
    function getCoordinates($address) {
        $apiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
        $encodedAddress = urlencode($address);
        $url = "https://maps.googleapis.com/maps/api/geocode/json?address=$encodedAddress&key=$apiKey";
        $ctx = stream_context_create([
            'http' => ['timeout' => 2.0]
        ]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response) {
            $data = json_decode($response);
            if (isset($data->status) && $data->status === 'OK' && isset($data->results[0])) {
                return [
                    'lat' => $data->results[0]->geometry->location->lat,
                    'lng' => $data->results[0]->geometry->location->lng
                ];
            }
        }
        return false;
    }

    if (!function_exists('getDistance')) {
        function getDistance($lat1, $lon1, $lat2, $lon2) {
            $earth_radius = 6371; // Earth radius in km
            $dLat = deg2rad($lat2 - $lat1);
            $dLon = deg2rad($lon2 - $lon1);
            $a = sin($dLat / 2) * sin($dLat / 2) +
                 cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
                 sin($dLon / 2) * sin($dLon / 2);
            $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
            return $earth_radius * $c; // Distance in km
        }
    }

    if ($from_lat !== null && $from_lng !== null && floatval($from_lat) != 0) {
        $fromCoords = ['lat' => $from_lat, 'lng' => $from_lng];
    } else {
        $fromCoords = getCoordinates($from_address) ?: ['lat' => 19.0760, 'lng' => 72.8777];
    }

    if ($to_lat !== null && $to_lng !== null && floatval($to_lat) != 0) {
        $toCoords = ['lat' => $to_lat, 'lng' => $to_lng];
    } else {
        $toCoords = getCoordinates($to_address) ?: ['lat' => 19.2183, 'lng' => 72.9781];
    }

    // Check if there is any active vendor/driver within 5 km
    $vendors_sql = "SELECT latitude, longitude FROM drivers WHERE status = 'active'";
    $vendors_res = $conn->query($vendors_sql);
    
    $vendor_found = false;
    $radius_km = 5;

    if ($vendors_res && $vendors_res->num_rows > 0) {
        while ($row = $vendors_res->fetch_assoc()) {
            // Ignore unset (empty or 0.0) coordinates since we cannot verify their actual distance
            if (!empty($row['latitude']) && !empty($row['longitude']) && floatval($row['latitude']) != 0 && floatval($row['longitude']) != 0) {
                $dist = getDistance($fromCoords['lat'], $fromCoords['lng'], floatval($row['latitude']), floatval($row['longitude']));
                if ($dist <= $radius_km) {
                    $vendor_found = true;
                    break;
                }
            }
        }
    }

    if (!$vendor_found) {
        echo json_encode(["status" => "error", "message" => "No local taxi service is currently available in your area."]);
        exit;
    }

    // ✅ Determine booking status
    $booking_status = "Pending";

    $current_date = date('Y-m-d');
    $current_time = date('H:i:s');
    $created_at   = date('Y-m-d H:i:s');

    // ✅ Begin transaction
    $conn->begin_transaction();

    // ✅ Check if user exists
    $check_user_sql = "SELECT phone_number FROM users WHERE phone_number = ?";
    $check_user_stmt = $conn->prepare($check_user_sql);
    if (!$check_user_stmt) {
        throw new Exception("User check failed: " . $conn->error);
    }
    $check_user_stmt->bind_param("s", $phone_number);
    $check_user_stmt->execute();
    $user_exists = $check_user_stmt->get_result()->num_rows > 0;
    $check_user_stmt->close();

    // ✅ If user exists → check referral
    if ($user_exists) {
        // ⭐ Referral check
        $referral_sql = "SELECT referred_by FROM customer_referral WHERE customer_number = ?";
        $referral_stmt = $conn->prepare($referral_sql);
        if (!$referral_stmt) {
            throw new Exception("Referral check failed: " . $conn->error);
        }

        $referral_stmt->bind_param("s", $phone_number);
        $referral_stmt->execute();
        $referral_result = $referral_stmt->get_result();

        if ($referral_result->num_rows > 0) {
            $referral_data = $referral_result->fetch_assoc();
            $referred_by = $referral_data['referred_by'];

            // Increment available_spin by 3 for the referrer
            $update_spin_sql = "UPDATE users SET available_spin = available_spin + 3 WHERE phone_number = ?";
            $update_spin_stmt = $conn->prepare($update_spin_sql);
            if (!$update_spin_stmt) {
                throw new Exception("Spin update failed: " . $conn->error);
            }

            $update_spin_stmt->bind_param("s", $referred_by);
            $update_spin_stmt->execute();
            $update_spin_stmt->close();
        }
        $referral_stmt->close();

        // ✅ Update existing user
        $user_sql = "UPDATE users 
                     SET booking_number=?, name=?, email=?, city=?, pincode=?, created_at=? 
                     WHERE phone_number=?";
        $user_stmt = $conn->prepare($user_sql);
        if (!$user_stmt) {
            throw new Exception("User update failed: " . $conn->error);
        }
        $user_stmt->bind_param("sssssss", $booking_number, $name, $email, $city, $pincode, $created_at, $phone_number);
        $user_stmt->execute();
        $user_stmt->close();

    } else {
        // ✅ Insert new user
        $agent_id = 1; // default
        $user_sql = "INSERT INTO users (phone_number, name, email, city, pincode, agent_id) 
                     VALUES (?, ?, ?, ?, ?, ?)";
        $user_stmt = $conn->prepare($user_sql);
        if (!$user_stmt) {
            throw new Exception("User insert failed: " . $conn->error);
        }
        $user_stmt->bind_param("sssssi", $phone_number, $name, $email, $city, $pincode, $agent_id);
        $user_stmt->execute();
        $user_stmt->close();
    }

    // For Local Taxi bookings, the Vendor Earnings must be exactly equal to the Customer Paid Amount.
    // Vendor receives 100% of the booking amount. No Agni commission or platform fee is deducted.
    $agni_amount = 0;
    $vendor_amount = $total_amount;

    // ✅ Insert booking
    $booking_sql = "INSERT INTO bookings (
        from_address, to_address, distance, car_type, total_amount, trip_type,
        date, time, mobile, otp, vendor_amount, agni_amount, booker_id, booking_status
    ) VALUES (?, ?, ?, ?, ?, 'Local-taxi', ?, ?, ?, ?, ?, ?, ?, ?)";

    $booking_stmt = $conn->prepare($booking_sql);
    if (!$booking_stmt) {
        throw new Exception("Booking insert failed: " . $conn->error);
    }

    $booking_stmt->bind_param(
        'ssdsdssssddss',
        $from_address,
        $to_address,
        $distance,
        $car_type,
        $total_amount,
        $current_date,
        $current_time,
        $booking_number,
        $otp,
        $vendor_amount,
        $agni_amount,
        $phone_number,
        $booking_status
    );

    if ($booking_stmt->execute()) {
        $booking_id = $conn->insert_id;
        $conn->commit();
        
        try {
            require_once __DIR__ . '/send_new_booking_notification.php';
            trigger_new_booking_notification($booking_id, $fromCoords['lat'], $fromCoords['lng']);
        } catch (Throwable $e) {
            error_log("FCM Notification error: " . $e->getMessage());
        }

        // Send WhatsApp notification to the customer
        try {
            require_once __DIR__ . '/notification_helper.php';
            sendBookingWhatsAppNotification($booking_id, $conn);
        } catch (Throwable $e) {
            error_log("WhatsApp Booking Notification error: " . $e->getMessage());
        }

        echo json_encode([
            "status" => "success",
            "message" => "Booking and customer data saved successfully",
            "booking_id" => $booking_id,
            "booking_status" => $booking_status
        ]);
    } else {
        throw new Exception("Booking save failed: " . $booking_stmt->error);
    }

    $booking_stmt->close();

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

$conn->close();
?>
