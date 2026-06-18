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

    // ✅ Fetch trip cost details
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
            
            // Check active discount for this trip type/scope
            if ($row['trip_type'] === 'One-way') {
                $row_discount_percentage = 0;
                $row_discounted_price = 0;
                $today = date('Y-m-d');
                
                $tableCheck = $conn->query("SHOW TABLES LIKE 'discounts'");
                if ($tableCheck && $tableCheck->num_rows > 0) {
                    $discount_stmt = $conn->prepare("SELECT discount_type, discount_value FROM discounts WHERE status = 'active' AND apply_scope = 'One-way' AND ? BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1");
                    if ($discount_stmt) {
                        $discount_stmt->bind_param("s", $today);
                        $discount_stmt->execute();
                        $discount_res = $discount_stmt->get_result();
                        if ($discount_res && $discount_res->num_rows > 0) {
                            $discount_row = $discount_res->fetch_assoc();
                            $disc_type = $discount_row['discount_type'];
                            $disc_val = floatval($discount_row['discount_value']);
                            if ($disc_type === 'percentage') {
                                $row_discount_percentage = $disc_val;
                                $row_discounted_price = $baseAmount + ($baseAmount * $row_discount_percentage / 100);
                            } else if ($disc_type === 'fixed') {
                                $row_discount_percentage = ($baseAmount > 0) ? (($disc_val / $baseAmount) * 100) : 0;
                                $row_discounted_price = $baseAmount + $disc_val;
                            }
                        }
                        $discount_stmt->close();
                    }
                }
                $row['discount_percentage'] = $row_discount_percentage;
                $row['discounted_price'] = $row_discounted_price;
            } else {
                // non-One-way trip
                $row['discount_percentage'] = $discount_percentage;
                $row['discounted_price'] = $baseAmount + ($baseAmount * $discount_percentage / 100);
            }
        } else {
            $row['discount_percentage'] = 0;
            $row['discounted_price'] = 0;
        }
        $costStmt->close();
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
