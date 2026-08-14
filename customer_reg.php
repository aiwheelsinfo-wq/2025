<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

include 'db_connect.php';

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "Database connection failed: " . ($conn->connect_error ?? 'No connection')]);
    exit;
}

// Handle JSON OR Form input safely
$raw_input = file_get_contents("php://input");
$data = json_decode($raw_input, true);
if (!$data && !empty($_POST)) {
    $data = $_POST;
}

date_default_timezone_set('Asia/Kolkata');

// Extract values safely
$phone_number = $data['phone_number'] ?? '';
$booking_number = $data['booking_number'] ?? '';
$name = $data['name'] ?? '';
$email = $data['email'] ?? '';
$city = $data['city'] ?? 'Not Filled';
$pincode = $data['pincode'] ?? '0';
$agency_name = $data['agency_name'] ?? '';
$created_at = date('Y-m-d H:i:s');

if (empty($phone_number)) {
    echo json_encode(["status" => "error", "message" => "Phone number is required"]);
    exit;
}

// Check if user exists
$checkStmt = $conn->prepare("SELECT id FROM users WHERE phone_number = ?");
$checkStmt->bind_param("s", $phone_number);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows > 0) {
    // Update existing user
    $sql = "UPDATE users SET
                booking_number = ?,
                name = ?, 
                email = ?, 
                city = ?, 
                pincode = ?, 
                agency_name = ?, 
                created_at = ? 
            WHERE phone_number = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssss", $booking_number, $name, $email, $city, $pincode, $agency_name, $created_at, $phone_number);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "User updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error updating user: " . $stmt->error]);
    }
    $stmt->close();
} else {
    // Insert new user
    $agent_id = $phone_number;
    $available_spins = 3;
    $sql = "INSERT INTO users (phone_number, name, email, city, pincode, agency_name, created_at, agent_id, available_spin, booking_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssis", $phone_number, $name, $email, $city, $pincode, $agency_name, $created_at, $agent_id, $available_spins, $booking_number);
    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "User registered successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Error registering user: " . $stmt->error]);
    }
    $stmt->close();
}

$checkStmt->close();
$conn->close();
?>