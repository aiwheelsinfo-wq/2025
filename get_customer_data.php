<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Replace with specific origin in production
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';

// Check database connection
if (!$conn) {
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit;
}

// Determine request method
$method = $_SERVER['REQUEST_METHOD'];
$phone_number = '';

if ($method === 'POST') {
    // Handle POST request (JSON body)
    $data = json_decode(file_get_contents("php://input"), true);
    $phone_number = $data['phone_number'] ?? '';
} elseif ($method === 'GET') {
    // Handle GET request (query parameter)
    $phone_number = $_GET['phone_number'] ?? '';
} else {
    echo json_encode(["status" => "error", "message" => "Unsupported request method"]);
    exit;
}

// Validate phone number (e.g., 10 digits)
if (empty($phone_number) || !preg_match('/^\d{10}$/', $phone_number)) {
    echo json_encode(["status" => "error", "message" => "Invalid phone number"]);
    exit;
}

$sql = "SELECT name, email, city, pincode, phone_number, reward_point, available_spin, badge, agency_name FROM users WHERE phone_number = ?";
$stmt = $conn->prepare($sql);


$stmt->bind_param("s", $phone_number);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    echo json_encode([
        "status" => "success",
        "user" => $user
    ]);
} else {
    echo json_encode(["status" => "not_found", "message" => "Phone number not found"]);
}

$stmt->close();
$conn->close();
?>