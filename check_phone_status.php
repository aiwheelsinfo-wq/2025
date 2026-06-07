<?php
header("Access-Control-Allow-Origin: *"); // Optional for testing
header("Content-Type: application/json");

include 'db_connect.php'; // ✅ your DB connection file

$response = ['status' => 'error'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- POST REQUEST: Check user status ---
    $phone = $_POST['phone'] ?? '';

    if (!empty($phone)) {
        $stmt = $conn->prepare("SELECT STATUS FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            if (strtolower($row['STATUS']) === 'blocked') {
                $response['status'] = 'blocked';
            } else {
                $response['status'] = 'active';
            }
        } else {
            $response['status'] = 'not_found';
        }

        $stmt->close();
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // --- GET REQUEST: Fetch full user data ---
    $phone = $_GET['phone_number'] ?? '';

    if (!empty($phone)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone_number = ?");
        $stmt->bind_param("s", $phone);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $response = [
                'status' => 'success',
                'user' => $row
            ];
        } else {
            $response = [
                'status' => 'not_found'
            ];
        }

        $stmt->close();
    }
} else {
    $response = [
        'status' => 'error',
        'message' => 'Unsupported request method'
    ];
}

$conn->close();
echo json_encode($response);
?>
