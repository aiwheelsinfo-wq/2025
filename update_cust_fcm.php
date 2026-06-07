<?php

header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

include 'db_connect.php';

// 🔴 Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        "success" => false,
        "message" => "Invalid request method"
    ]);
    exit;
}

// 🔴 Get input
$phone_number = isset($_POST['phone_number']) ? trim($_POST['phone_number']) : '';
$fcm_token    = isset($_POST['fcm_token']) ? trim($_POST['fcm_token']) : '';

// 🔴 Validate input
if (empty($phone_number) || empty($fcm_token)) {
    echo json_encode([
        "success" => false,
        "message" => "phone_number and fcm_token are required"
    ]);
    exit;
}

// 🔴 Prepare statement (prevents SQL injection)
$stmt = $conn->prepare("UPDATE users SET fcm_token = ? WHERE phone_number = ?");

if (!$stmt) {
    echo json_encode([
        "success" => false,
        "message" => "Prepare failed: " . $conn->error
    ]);
    exit;
}

$stmt->bind_param("ss", $fcm_token, $phone_number);

// 🔴 Execute
if ($stmt->execute()) {

    if ($stmt->affected_rows > 0) {
        echo json_encode([
            "success" => true,
            "message" => "FCM token updated successfully"
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "No user found or token already same"
        ]);
    }

} else {
    echo json_encode([
        "success" => false,
        "message" => "Execution failed: " . $stmt->error
    ]);
}

$stmt->close();
$conn->close();

?>