<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

include 'db_connect.php'; // Your DB connection ($conn)

// Handle OPTIONS request (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["success" => false, "message" => "Invalid request method"]);
    exit;
}

// Retrieve POST data
$trip_type       = $_POST['trip_type'] ?? 'One-way';
$car_type        = $_POST['car_type'] ?? '';
$from_address    = $_POST['from_address'] ?? '';
$to_address      = $_POST['to_address'] ?? '';
$distance        = $_POST['distance'] ?? 0;
$date            = $_POST['date'] ?? date('Y-m-d');
$tripTime        = $_POST['tripTime'] ?? date('H:i:s');
$tripTimeParsed = strtotime($tripTime);
if ($tripTimeParsed !== false) {
    $tripTime = date('H:i:s', $tripTimeParsed);
}
$return_date     = $_POST['return_date'] ?? '1970-01-01';
$return_time     = $_POST['return_time'] ?? '00:00:00';
$returnTimeParsed = strtotime($return_time);
if ($returnTimeParsed !== false) {
    $return_time = date('H:i:s', $returnTimeParsed);
}
$name            = $_POST['name'] ?? '';
$email           = $_POST['email'] ?? '';
$pincode         = $_POST['pincode'] ?? '';
$city            = $_POST['city'] ?? '';
$base_charge     = $_POST['base_charge'] ?? 0;
$driver_ta       = $_POST['driver_ta'] ?? 0;
$toll_charge     = $_POST['toll_charge'] ?? 0;
$total_amount    = $_POST['total_amount'] ?? 0;
$payment_type    = $_POST['payment_type'] ?? 'Pending';
$userNumber      = $_POST['userNumber'] ?? '';
$agent_commission = $_POST['agent_commission'] ?? 0;
$agni_amount     = $_POST['agni_amount'] ?? 0;
$vendor_amount   = $_POST['vendor_amount'] ?? 0;
$user_type       = $_POST['user_type'] ?? 'customer';
$customer_mob    = $_POST['customer_mob'] ?? '';
$gst             = $_POST['gst'] ?? '';
$gst_number      = $_POST['gst_number'] ?? '';
$business_name   = $_POST['business_name'] ?? '';
$business_address= $_POST['business_address'] ?? '';
$business_pincode= $_POST['business_pincode'] ?? '';
$bookingId       = $_POST['bookingId'] ?? ''; // if updating existing booking

date_default_timezone_set('Asia/Kolkata');
$booked_at = date('Y-m-d H:i:s');

// Decide contact number
$contact_number = ($user_type === 'agent' && !empty($customer_mob)) ? $customer_mob : $userNumber;

// Generate OTP
$otp = rand(1000, 9999);

// Insert or update user info in users table
$checkUserSql = "SELECT id FROM users WHERE phone_number='$contact_number'";
$checkResult = mysqli_query($conn, $checkUserSql);

if (mysqli_num_rows($checkResult) > 0) {
    // User exists, update
    $updateUserSql = "UPDATE users SET name='$name', email='$email', pincode='$pincode', city='$city' WHERE phone_number='$contact_number'";
    mysqli_query($conn, $updateUserSql);
} else {
    // Insert new user
    if (!empty($contact_number)) {
        $insertUserSql = "INSERT INTO users (name, email, phone_number, pincode, city, agent_id) VALUES ('$name', '$email', '$contact_number', '$pincode', '$city', '$userNumber')";
        mysqli_query($conn, $insertUserSql);
    }
}

// ✅ Insert One-way trip into bookings
$insertBookingSql = "INSERT INTO bookings 
(trip_type, car_type, from_address, to_address, distance, date, time, booked_at, return_date, return_time, mobile, base_charge, driver_ta, toll_charge, total_amount, payment_type, agent_commission, agni_amount, vendor_amount, booker_id, gst, gst_number, business_name, business_address, business_pincode, otp, booking_status)
VALUES
('$trip_type', '$car_type', '$from_address', '$to_address', '$distance', '$date', '$tripTime', '$booked_at', '$return_date', '$return_time', '$contact_number', '$base_charge', '$driver_ta', '$toll_charge', '$total_amount', '$payment_type', '$agent_commission', '$agni_amount', '$vendor_amount', '$userNumber', '$gst', '$gst_number', '$business_name', '$business_address', '$business_pincode', '$otp', 'Pending')";

if (mysqli_query($conn, $insertBookingSql)) {
    $booking_id = mysqli_insert_id($conn);
    echo json_encode([
        "success" => true,
        "message" => "Booking created successfully",
        "booking_id" => $booking_id
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Error inserting booking: " . mysqli_error($conn)
    ]);
}

mysqli_close($conn);
?>
