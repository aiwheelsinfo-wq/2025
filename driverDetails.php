<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db_connect.php'; // Ensure this file exists and is correct

header('Content-Type: application/json');

if (isset($_GET['driver_id'])) {
    
    $driver_id = $_GET['driver_id'];

    // Prepare query (assuming 'driver_id' is the correct column)
    $stmt = $conn->prepare("SELECT phone_number, full_name, vehicle_id, vehicle_name, vehicle_type FROM drivers WHERE phone_number = ?");
    
    if ($stmt === false) {
        echo json_encode(["status" => "error", "message" => "Query preparation failed"]);
        exit;
    }

    $stmt->bind_param("i", $driver_id); // 'i' for integer, change to 's' if driver_id is a string
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $driver = $result->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $driver]);
    } else {
        echo json_encode(["status" => "error", "message" => "Driver not found"]);
    }

    $stmt->close();
} else {
    echo json_encode(["status" => "error", "message" => "Missing driver_id parameter"]);
}

$conn->close();
?>
