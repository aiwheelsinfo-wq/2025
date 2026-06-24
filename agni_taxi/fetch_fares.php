<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

include 'db_connect.php'; // Your DB connection file

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Get user's phone number from query parameter
$phone_number = isset($_GET['phone_number']) ? $_GET['phone_number'] : '';

if (empty($phone_number)) {
    echo json_encode(["error" => "Phone number is required"]);
    exit;
}

// 1️⃣ Count previous bookings for this user
$booking_sql = "SELECT COUNT(*) AS booking_count FROM bookings WHERE booker_id = ?";
$stmt = $conn->prepare($booking_sql);

if (!$stmt) {
    echo json_encode(["error" => "Prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("s", $phone_number);
$stmt->execute();
$booking_result = $stmt->get_result();
$booking_count = 0;

if ($booking_result && $row = $booking_result->fetch_assoc()) {
    $booking_count = (int)$row['booking_count'];
}

// ✅ Check active discount for Local-taxi from database
$discount_percent = 0;
$today = date('Y-m-d');
$tableCheck = $conn->query("SHOW TABLES LIKE 'discounts'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $discount_stmt = $conn->prepare("SELECT discount_type, discount_value FROM discounts WHERE status = 'active' AND apply_scope = 'Local-taxi' AND ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1");
    if ($discount_stmt) {
        $discount_stmt->bind_param("s", $today);
        $discount_stmt->execute();
        $discount_res = $discount_stmt->get_result();
        if ($discount_res && $discount_res->num_rows > 0) {
            $discount_row = $discount_res->fetch_assoc();
            if ($discount_row['discount_type'] === 'percentage') {
                $discount_percent = floatval($discount_row['discount_value']);
            }
        }
        $discount_stmt->close();
    }
}

// Fallback to default 10% discount for <= 5 bookings if no database discount is active
if ($discount_percent === 0 && $booking_count <= 5) {
    $discount_percent = 10;
}

// 2️⃣ Fetch fares
$sql = "SELECT id, km, Hatchback, Sedan, Ertiga FROM local_texi_fare_chart;";
$result = $conn->query($sql);

if ($result) {
    if ($result->num_rows > 0) {
        $fares = array();
        while ($row = $result->fetch_assoc()) {
            // Make sure values are numeric
            $hatchback = (float)$row['Hatchback'];
            $sedan = (float)$row['Sedan'];
            $ertiga = (float)$row['Ertiga'];

            // Calculate discounted prices
            $row['discount_percent'] = $discount_percent;
            $row['Hatchback_discounted'] = round($hatchback * (1 - $discount_percent / 100), 2);
            $row['Sedan_discounted'] = round($sedan * (1 - $discount_percent / 100), 2);
            $row['Ertiga_discounted'] = round($ertiga * (1 - $discount_percent / 100), 2);

            $fares[] = $row;
        }
        echo json_encode($fares);
    } else {
        echo json_encode(["message" => "No fare data found"]);
    }
} else {
    echo json_encode(["error" => "Query failed: " . $conn->error]);
}

$conn->close();
?>
