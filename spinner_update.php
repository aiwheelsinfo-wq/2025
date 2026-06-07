<?php
// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Include DB connection
include "db_connect.php"; // defines $conn

/* ===============================
   GET: Increase/Decrease Spins for ALL Users
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if (empty($action)) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Action is required. Use 'increase' or 'decrease'."
        ]);
        exit();
    }

    // Choose the correct SQL query
    if ($action === 'increase') {
        $sql = "UPDATE users SET available_spin = available_spin + 1";
    } elseif ($action === 'decrease') {
        // Ensure spins do not go below zero
        $sql = "UPDATE users SET available_spin = GREATEST(available_spin - 1, 0)";
    } else {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "Invalid action. Use 'increase' or 'decrease'."
        ]);
        exit();
    }

    // Execute the update
    if ($conn->query($sql) === TRUE) {
        echo json_encode([
            "status" => "success",
            "message" => "Spins {$action}d successfully for all users"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Failed to {$action} spins for all users",
            "error_detail" => $conn->error
        ]);
    }

    $conn->close();
    exit();
}

/* ===============================
   PUT: Award Points & Decrease Spin for Specific User
   =============================== */
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $input = json_decode(file_get_contents("php://input"), true);

    if (!isset($input['phone_number']) || !isset($input['reward_point'])) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "phone_number and reward_point are required"
        ]);
        exit();
    }

    $phone_number = $input['phone_number'];
    $reward_point = (int)$input['reward_point'];

    // Fetch current points and spins
    $query = "SELECT reward_point, available_spin FROM users WHERE phone_number = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $phone_number);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "User not found"
        ]);
        exit();
    }

    $user = $result->fetch_assoc();

    // Check spins availability
    if ((int)$user['available_spin'] <= 0) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "No spins available"
        ]);
        exit();
    }

    // Calculate new points and spins
    $new_points = (int)$user['reward_point'] + $reward_point;
    $new_spins  = (int)$user['available_spin'] - 1;

    // Update points and spins
    $update = "UPDATE users SET reward_point = ?, available_spin = ? WHERE phone_number = ?";
    $updateStmt = $conn->prepare($update);
    $updateStmt->bind_param("iis", $new_points, $new_spins, $phone_number);

    if ($updateStmt->execute()) {
        echo json_encode([
            "status" => "success",
            "message" => "Points updated successfully",
            "new_reward_point" => $new_points,
            "remaining_spins" => $new_spins
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Failed to update points"
        ]);
    }

    $stmt->close();
    $updateStmt->close();
    $conn->close();
    exit();
}

// Invalid request method
http_response_code(405);
echo json_encode([
    "status" => "error",
    "message" => "Invalid request method. Use GET (increase/decrease for all) or PUT (award points for one user)."
]);
?>
