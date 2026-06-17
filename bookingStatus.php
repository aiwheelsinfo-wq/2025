<?php
include 'db_connect.php';

header('Content-Type: application/json');

// ✅ Check phone_number
if (!isset($_GET["phone_number"]) || empty($_GET["phone_number"])) {
    echo json_encode(["status" => "error", "message" => "phone_number parameter missing"]);
    exit;
}

$phone_number = trim($_GET["phone_number"]);

// ✅ Set discount percentage
$discount_percentage = 10; 

// ✅ Get bookings
$query = "SELECT * FROM bookings WHERE booker_id = ? AND booking_status != 'temp'";
$stmt = $conn->prepare($query);
if (!$stmt) {
    echo json_encode(["status" => "error", "message" => "Failed to prepare bookings query"]);
    exit;
}
$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

$bookings = [];

while ($row = $result->fetch_assoc()) {

    // ✅ Fetch driver lat/lng
    if (!empty($row['driver_id'])) {
        $driverQuery = "SELECT latitude, longitude FROM drivers WHERE phone_number = ? LIMIT 1";
        $driverStmt = $conn->prepare($driverQuery);
        if ($driverStmt) {
            $driverStmt->bind_param("s", $row['driver_id']);
            $driverStmt->execute();
            $driverResult = $driverStmt->get_result();
            $driverData = $driverResult->fetch_assoc();
            $row['driver_latitude'] = $driverData['latitude'] ?? null;
            $row['driver_longitude'] = $driverData['longitude'] ?? null;
            $driverStmt->close();
        } else {
            $row['driver_latitude'] = null;
            $row['driver_longitude'] = null;
        }
    } else {
        $row['driver_latitude'] = null;
        $row['driver_longitude'] = null;
    }

    // ✅ Fetch trip cost details for non-One-way trips
    if ($row['trip_type'] !== 'One-way') {
        $tripCostQuery = "SELECT kmRate, baseAmount, extraKMAmount, extraHoursAmount, packageKm, packageHours, gstPercent, driver_allowance
                          FROM tripCostTable 
                          WHERE tripType = ? AND carType = ?";
        $costStmt = $conn->prepare($tripCostQuery);
        if ($costStmt) {
            $costStmt->bind_param("ss", $row['trip_type'], $row['car_type']);
            $costStmt->execute();
            $costResult = $costStmt->get_result();
            $costData = $costResult->fetch_assoc();

            if ($costData) {
                $row = array_merge($row, $costData);

                $baseAmount = isset($costData['baseAmount']) ? floatval($costData['baseAmount']) : 0;
                $row['discount_percentage'] = $discount_percentage;
                $row['discounted_price'] = $baseAmount + ($baseAmount * $discount_percentage / 100);
            }

            $costStmt->close();
        }
    } else {
        // ✅ One-way trip discount
        $baseAmount = isset($row['base_charge']) ? floatval($row['base_charge']) : 0;
        $row['discount_percentage'] = $discount_percentage;
        $row['discounted_price'] = $baseAmount + ($baseAmount * $discount_percentage / 100);
    }

    $bookings[] = $row;
}

$stmt->close();

// ✅ Return JSON
echo json_encode([
    "status" => count($bookings) > 0 ? "success" : "no_data",
    "data" => $bookings
], JSON_PRETTY_PRINT);
?>
