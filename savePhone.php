<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
date_default_timezone_set('Asia/Kolkata');

include 'db_connect.php';
header('Content-Type: application/json');

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $phone_number = $_POST['phone_number'] ?? '';
    $accountType = $_POST['userType'] ?? '';
    $fcm_token = $_POST['fcm_token'] ?? '';
    $created_at = date('Y-m-d H:i:s');

    if (empty($phone_number)) {
        echo json_encode(["success" => false, "message" => "Phone number is required."]);
        exit;
    }

    // Step 1: Check if user is blocked
    $checkStatus = $conn->prepare("SELECT status FROM users WHERE phone_number = ?");
    $checkStatus->bind_param("s", $phone_number);
    $checkStatus->execute();
    $statusResult = $checkStatus->get_result();

    if ($statusRow = $statusResult->fetch_assoc()) {
        if (strtolower($statusRow['status']) === 'blocked') {
            echo json_encode(["success" => false, "message" => "Phone number is blocked."]);
            $checkStatus->close();
            $conn->close();
            exit;
        }
    }
    $checkStatus->close();

    // Step 2: Check if user already exists
    $checkUser = $conn->prepare("SELECT id FROM users WHERE phone_number = ?");
    $checkUser->bind_param("s", $phone_number);
    $checkUser->execute();
    $checkUser->store_result();

    if ($checkUser->num_rows > 0) {
        // Update user
        $update_stmt = $conn->prepare("UPDATE users SET fcm_token = ?, accountType = ?, created_at = ? WHERE phone_number = ?");
        $update_stmt->bind_param("ssss", $fcm_token, $accountType, $created_at, $phone_number);

        if ($update_stmt->execute()) {
            echo json_encode(["success" => true, "message" => "FCM token updated successfully!"]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to update FCM token."]);
        }
        $update_stmt->close();
    } else {
    // ✅ Set default values
    $created_at = date('Y-m-d H:i:s'); // Current timestamp
    $additionalSpins = 3;              // Spins to add

    // Insert new user and add 3 spins
    $insert_stmt = $conn->prepare("
        INSERT INTO users (phone_number, accountType, fcm_token, created_at, agent_id, available_spin)
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    $available_spins = $additionalSpins; // Initial spins = 3

    $insert_stmt->bind_param(
        "sssssi",
        $phone_number,
        $accountType,
        $fcm_token,
        $created_at,
        $phone_number,   // agent_id
        $available_spins
    );

    if ($insert_stmt->execute()) {
        echo json_encode([
            "success" => true,
            "message" => "Phone Number Saved Successfully! 3 Spins added."
        ]);
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to save phone number."
        ]);
    }

    $insert_stmt->close();
}


    $checkUser->close();
} else {
    echo json_encode(["success" => false, "message" => "Invalid request method."]);
}

$conn->close();
?>
