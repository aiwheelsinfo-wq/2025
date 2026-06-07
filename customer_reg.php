<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . $conn->connect_error]);
    exit;
}

// Handle JSON input
$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(["status" => "error", "message" => "Invalid JSON data: " . json_last_error_msg()]);
    exit;
}

date_default_timezone_set('Asia/Kolkata');

// Extract values safely
$phone_number = $data['phone_number'] ?? '';
$booking_number=$data['booking_number']??'';
$name = $data['name'] ?? '';
$email = $data['email'] ?? '';
$city = $data['city'] ?? '';
$pincode = $data['pincode'] ?? '';
$agency_name = $data['agency_name'] ?? ''; // New field, optional
$created_at = date('Y-m-d H:i:s');

// Validate inputs


// Update existing user
$sql = "UPDATE users SET
            booking_number=?,
            name = ?, 
            email = ?, 
            city = ?, 
            pincode = ?, 
            agency_name = ?, 
            created_at = ? 
        WHERE phone_number = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(["status" => "error", "message" => "Failed to prepare statement: " . $conn->error]);
    exit;
}

$stmt->bind_param("ssssssss",$booking_number, $name, $email, $city, $pincode, $agency_name, $created_at, $phone_number);

if ($stmt->execute()) {
    if ($stmt->affected_rows > 0) {
        echo json_encode(["status" => "success", "message" => "User updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "No user found with phone number: $phone_number"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Error: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>