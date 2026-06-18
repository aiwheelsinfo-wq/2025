<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

$discount = 0;
$today = date('Y-m-d');
$discount_type = 'percentage';
$discount_value = 0.0;
$discount_name = 'Loyalty';

$tableCheck = $conn->query("SHOW TABLES LIKE 'discounts'");
if ($tableCheck && $tableCheck->num_rows > 0) {
    $discount_query = "SELECT name, discount_type, discount_value FROM discounts WHERE status = 'active' AND apply_scope = 'One-way' AND '$today' BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1";
    $discount_res = $conn->query($discount_query);

    if ($discount_res && $discount_res->num_rows > 0) {
        $row = $discount_res->fetch_assoc();
        $discount_type = $row['discount_type'];
        $discount_value = floatval($row['discount_value']);
        $discount_name = $row['name'];
        if ($row['discount_type'] === 'percentage') {
            $discount = (int)$row['discount_value'];
        } else {
            // fixed amount
            $baseAmount = 2500;
            $cost_res = $conn->query("SELECT baseAmount FROM tripCostTable WHERE tripType = 'One-way' AND baseAmount > 0 LIMIT 1");
            if ($cost_res && $cost_res->num_rows > 0) {
                $cost_row = $cost_res->fetch_assoc();
                $baseAmount = floatval($cost_row['baseAmount']);
            }
            $discount = intval(round(($row['discount_value'] / $baseAmount) * 100));
        }
    }
} else {
    // Get discount_percentage from old table if new discounts table doesn't exist yet
    $sql = "SELECT discount_percentage FROM discount_percentage LIMIT 1";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $discount = (int)$row['discount_percentage'];
        $discount_value = floatval($discount);
    }
}

// Car types
$carTypes = ["sedan", "hatchback", "ertiga", "innova", "crysta"];

// Sequential numbers from 1 to 10
$numbers = range(1, 10);

// Map first 5 numbers to car types and add discount
$response = [];
foreach ($carTypes as $index => $car) {
    $response[$car] = $numbers[$index] + $discount;
}

// Add discount details separately
$response['discount_percentage'] = $discount;
$response['discount_type'] = $discount_type;
$response['discount_value'] = $discount_value;
$response['discount_name'] = $discount_name;

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);

$conn->close();
?>

