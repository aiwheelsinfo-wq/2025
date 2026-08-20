<?php
include 'db_connect.php';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$bookingId = $_GET['bookingId'] ?? '';

if (empty($bookingId)) {
    echo json_encode(['error' => 'No invoice ID provided']);
    exit;
}

// Step 1: Get booking + user details
$query = "SELECT bookings.*, users.name, users.email, users.agency_name, users.city, users.pincode, users.accountType 
          FROM bookings 
          LEFT JOIN users ON bookings.mobile = users.phone_number 
          WHERE bookings.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $bookingId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['error' => 'Invoice not found']);
    exit;
}

$data = $result->fetch_assoc();

// If booked by an agent, get agent agency & contact info
if (!empty($data['booker_id'])) {
    $agent_stmt = $conn->prepare("SELECT agency_name, name AS agent_name, email AS agent_email, city AS agent_city, pincode AS agent_pincode, phone_number AS agent_phone, accountType AS agent_accountType FROM users WHERE phone_number = ?");
    if ($agent_stmt) {
        $agent_stmt->bind_param("s", $data['booker_id']);
        $agent_stmt->execute();
        $agent_res = $agent_stmt->get_result();
        if ($agent_res && $agent_row = $agent_res->fetch_assoc()) {
            $data['agent_agency_name'] = $agent_row['agency_name'];
            $data['agent_name']        = $agent_row['agent_name'];
            $data['agent_email']       = $agent_row['agent_email'];
            $data['agent_city']        = $agent_row['agent_city'];
            $data['agent_pincode']     = $agent_row['agent_pincode'];
            $data['agent_phone']       = $agent_row['agent_phone'];
            $data['agent_accountType'] = $agent_row['agent_accountType'];
        }
        $agent_stmt->close();
    }
}

// Step 1.5: Get driver details if assigned
$data['driver_name'] = '';
$data['driver_phone'] = '';
$data['vehicle_number'] = $data['vehicle_id'] ?? '';

if (!empty($data['driver_id'])) {
    $driver_stmt = $conn->prepare("SELECT full_name, phone_number, rc_no FROM drivers WHERE phone_number = ? OR driver_id = ? LIMIT 1");
    if ($driver_stmt) {
        $driver_stmt->bind_param("ss", $data['driver_id'], $data['driver_id']);
        $driver_stmt->execute();
        $driver_res = $driver_stmt->get_result();
        if ($driver_res && $driver_row = $driver_res->fetch_assoc()) {
            $data['driver_name'] = $driver_row['full_name'] ?? '';
            $data['driver_phone'] = $driver_row['phone_number'] ?? '';
            if (empty($data['vehicle_number']) && !empty($driver_row['rc_no'])) {
                $data['vehicle_number'] = $driver_row['rc_no'];
            }
        }
        $driver_stmt->close();
    }
}

$tripType = $data['trip_type'];
$carType = $data['car_type'];
$toAddress = urlencode($data['to_address']);

// Step 2: Get lat/lon from address using Google Geocoding API
$apiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
$geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$toAddress&key=$apiKey";
$geoResponse = file_get_contents($geoUrl);
$geoData = json_decode($geoResponse, true);

$toLat = null;
$toLon = null;

if ($geoData['status'] === 'OK' && isset($geoData['results'][0])) {
    $toLat = $geoData['results'][0]['geometry']['location']['lat'];
    $toLon = $geoData['results'][0]['geometry']['location']['lng'];
}

// Step 3: Get all tripCostTable rows for matching car/tripType
$costQuery = "SELECT * FROM tripCostTable WHERE tripType = ? AND carType = ?";
$costStmt = $conn->prepare($costQuery);
$costStmt->bind_param("ss", $tripType, $carType);
$costStmt->execute();
$costResult = $costStmt->get_result();

$finalCostRow = null;
$fallbackRow = null;

while ($row = $costResult->fetch_assoc()) {
    $hasCoords = isset($row['minLat'], $row['maxLat'], $row['minLon'], $row['maxLon']) &&
                 $row['minLat'] !== null && $row['maxLat'] !== null &&
                 $row['minLon'] !== null && $row['maxLon'] !== null;

    $isWithinBounds = false;

    if ($tripType === 'One-way' && $toLat !== null && $toLon !== null && $hasCoords) {
        if (
            $toLat >= $row['minLat'] && $toLat <= $row['maxLat'] &&
            $toLon >= $row['minLon'] && $toLon <= $row['maxLon']
        ) {
            $isWithinBounds = true;
        }
    }

    if ($tripType === 'One-way' && $isWithinBounds) {
        $finalCostRow = $row;
        break;
    }

    if ($tripType !== 'One-way' && !$finalCostRow) {
        $finalCostRow = $row;
    }

    if (!$hasCoords && !$fallbackRow) {
        $fallbackRow = $row;
    }
}

if (!$finalCostRow && $fallbackRow) {
    $finalCostRow = $fallbackRow;
}

// Step 4: Add tripCostTable fields to result
if ($finalCostRow) {
    $data['kmRate']           = $finalCostRow['kmRate'];
    $data['baseAmount']       = $finalCostRow['baseAmount'];
    $data['extraKMAmount']    = $finalCostRow['extraKMAmount'];
    $data['extraHoursAmount'] = $finalCostRow['extraHoursAmount'];
    $data['packageKm']        = $finalCostRow['packageKm'];
    $data['packageHours']     = $finalCostRow['packageHours'];
    $data['gstPercent']       = $finalCostRow['gstPercent'];
    $data['driver_allowance']  = $finalCostRow['driver_allowance'];
    $data['daily_limit']       = $finalCostRow['daily_limit'];
    $data['driverRate']       = $finalCostRow['driverRate'];
    $data['agni_share']       = $finalCostRow['agni_share'];
    if (!isset($data['agent_commission']) || empty($data['agent_commission'])) {
        $data['agent_commission'] = $finalCostRow['agni_share'] ?? 0;
    }
}
// Step 5: Fetch active discount for this trip type
$data['discount_type'] = null;
$data['discount_value'] = 0;
$data['discount_name'] = null;

$today = date('Y-m-d');
$tableCheck = $conn->query("SHOW TABLES LIKE 'discounts'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $discount_stmt = $conn->prepare("SELECT name, discount_type, discount_value FROM discounts WHERE status = 'active' AND apply_scope = ? AND ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1");
    if ($discount_stmt) {
        $discount_stmt->bind_param("ss", $tripType, $today);
        $discount_stmt->execute();
        $discount_res = $discount_stmt->get_result();
        if ($discount_res && $discount_res->num_rows > 0) {
            $discount_row = $discount_res->fetch_assoc();
            $data['discount_type'] = $discount_row['discount_type'];
            $data['discount_value'] = floatval($discount_row['discount_value']);
            $data['discount_name'] = $discount_row['name'];
        }
        $discount_stmt->close();
    }
}

// Check new customer discount for Local-taxi if no discount found
if ($tripType === 'Local-taxi' && floatval($data['discount_value']) == 0) {
    $booker_id = $data['booker_id'];
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS booking_count FROM bookings WHERE booker_id = ?");
    if ($count_stmt) {
        $count_stmt->bind_param("s", $booker_id);
        $count_stmt->execute();
        $count_res = $count_stmt->get_result();
        if ($count_res && $count_row = $count_res->fetch_assoc()) {
            $booking_count = intval($count_row['booking_count']);
            if ($booking_count <= 5) {
                $data['discount_type'] = 'percentage';
                $data['discount_value'] = 10.0;
                $data['discount_name'] = 'Loyalty';
            }
        }
        $count_stmt->close();
    }
}

if (!empty($data['time'])) {
    $data['time'] = date('h:i A', strtotime($data['time']));
}
if (!empty($data['return_time']) && $data['return_time'] !== '00:00:00') {
    $data['return_time'] = date('h:i A', strtotime($data['return_time']));
}

echo json_encode($data);
?>
