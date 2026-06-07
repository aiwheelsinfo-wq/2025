<?php
header('Content-Type: application/json');
include 'db_connect.php';

$userNumber = $_POST['userNumber'] ?? null;

if (!$userNumber) {
    echo json_encode(["success" => false, "message" => "Phone number is required"]);
    exit;
}

$stmt = $conn->prepare("SELECT name, email, city, pincode, phone_number FROM users WHERE phone_number = ? ORDER BY id DESC LIMIT 1");
$stmt->bind_param("s", $userNumber);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode(["success" => true, "data" => $row]);
} else {
    echo json_encode(["success" => false, "message" => "No user data found"]);
}

$conn->close();
?>
