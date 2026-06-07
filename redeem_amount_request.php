<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Include DB connection
include "db_connect.php";

// Handle POST (redeem request submission)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!isset($input['phone_number'], $input['amount'], $input['payment_id'])) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "phone_number, amount, and payment_id are required"
        ]);
        exit();
    }

    $phone_number = $input['phone_number'];
    $amount = (int)$input['amount'];
    $payment_id = $input['payment_id'];

    // if ($amount < 100) {
    //     http_response_code(400);
    //     echo json_encode([
    //         "status" => "error",
    //         "message" => "Minimum redeem amount is 100"
    //     ]);
    //     exit();
    // }

    // Check user's reward points
    $checkUser = $conn->prepare("SELECT reward_point FROM users WHERE phone_number = ?");
    $checkUser->bind_param("s", $phone_number);
    $checkUser->execute();
    $result = $checkUser->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "User not found"
        ]);
        exit();
    }

    $user = $result->fetch_assoc();
    $reward_point = (int)$user['reward_point'];

    if ($reward_point < $amount) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Insufficient reward points"
        ]);
        exit();
    }

    $conn->begin_transaction();

    try {
        $insert = $conn->prepare("INSERT INTO redeem_request (customer_id, amount, payment_id) VALUES (?, ?, ?)");
        $insert->bind_param("sis", $phone_number, $amount, $payment_id);
        $insert->execute();

        $new_points = $reward_point - $amount;
        $update = $conn->prepare("UPDATE users SET reward_point = ? WHERE phone_number = ?");
        $update->bind_param("is", $new_points, $phone_number);
        $update->execute();

        $conn->commit();

        echo json_encode([
            "status" => "success",
            "message" => "Redeem request successful",
            "remaining_points" => $new_points
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Failed to process redeem request",
            "error" => $e->getMessage()
        ]);
    }

    $checkUser->close();
    $insert->close();
    $update->close();
    $conn->close();
    exit();
}

// Handle GET (fetch redeem requests for a user)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_GET['phone_number'])) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "phone_number is required"
        ]);
        exit();
    }

    $phone_number = $_GET['phone_number'];

    $stmt = $conn->prepare("SELECT * FROM redeem_request WHERE customer_id = ? ORDER BY status='pending' DESC");
    $stmt->bind_param("s", $phone_number);
    $stmt->execute();
    $result = $stmt->get_result();

    $redeems = [];
    while ($row = $result->fetch_assoc()) {
        $redeems[] = $row;
    }

    echo json_encode([
        "status" => "success",
        "data" => $redeems
    ]);

    $stmt->close();
    $conn->close();
    exit();
}

http_response_code(405);
echo json_encode([
    "status" => "error",
    "message" => "Method not allowed"
]);
?>
