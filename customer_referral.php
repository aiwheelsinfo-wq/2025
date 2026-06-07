<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json");

include 'db_connect.php';

$response = ['status' => 'error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Read JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    $customer_number = $input['customer_number'] ?? '';
    $referred_by = $input['referred_by'] ?? '';

    // Validate required fields
    if (empty($customer_number) || empty($referred_by)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Both customer_number and referred_by are required'
        ]);
        exit;
    }

    // Step 1: Check if referred_by exists in user table
    $check_stmt = $conn->prepare("SELECT id, available_spin FROM users WHERE phone_number = ?");
    $check_stmt->bind_param("s", $referred_by);
    $check_stmt->execute();
    $check_stmt->store_result();
    $check_stmt->bind_result($user_id, $available_spin);

    if ($check_stmt->num_rows === 0) {
        // referred_by does not exist
        $response = [
            'status' => 'error',
            'message' => 'Invalid referred_by. User not found.'
        ];
        $check_stmt->close();
        echo json_encode($response);
        exit;
    }

    $check_stmt->fetch(); // fetch user_id and available_spin
    $check_stmt->close();

    // Step 2: Insert into customer_referral table
    $stmt = $conn->prepare("INSERT INTO customer_referral (customer_number, referred_by) VALUES (?, ?)");
    $stmt->bind_param("ss", $customer_number, $referred_by);

    if ($stmt->execute()) {
        // ✅ Step 3: Update user's available_spin (+3)
        $update_stmt = $conn->prepare("UPDATE users SET available_spin = available_spin + 3 WHERE phone_number = ?");
        $update_stmt->bind_param("s", $referred_by);
        $update_stmt->execute();
        $update_stmt->close();

        $response = [
            'status' => 'success',
            'message' => 'Referral added successfully and spin updated',
            'inserted_id' => $stmt->insert_id
        ];
    } else {
        $response = [
            'status' => 'error',
            'message' => 'Database insert failed: ' . $stmt->error
        ];
    }

    $stmt->close();
} else {
    $response = [
        'status' => 'error',
        'message' => 'Invalid request method. Use POST.'
    ];
}

$conn->close();
echo json_encode($response);
?>
