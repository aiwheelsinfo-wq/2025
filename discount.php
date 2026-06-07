<?php
header('Content-Type: application/json');
require_once 'db_connect.php';

// Get discount_percentage from table
$sql = "SELECT discount_percentage FROM discount_percentage LIMIT 1";
$result = $conn->query($sql);

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $discount = (int)$row['discount_percentage'];
} else {
    $discount = 0; // default if table empty
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

// Add discount_percentage separately
$response['discount_percentage'] = $discount;

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);

$conn->close();
?>
