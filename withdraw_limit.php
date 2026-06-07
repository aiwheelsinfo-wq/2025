<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';

// Helper: Convert ENUM to boolean and back
function toBool($val) {
    return strtolower(trim($val)) === 'true';
}
function toEnum($val) {
    return $val ? 'true' : 'false';
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -----------------------------
// 1️⃣ GET METHOD - Fetch data
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $query = $conn->query("SELECT * FROM withdraw_settings LIMIT 1");

    if (!$query || $query->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Withdraw settings not found"]);
        exit;
    }

    $settings = $query->fetch_assoc();
    $conn->close();

    $response = [
        "withdrawLimit" => [
            "max" => (int)$settings['withdraw_max'],
            "status" => toBool($settings['withdraw_status'])
        ],
        "numberOfWithdraws" => [
            "max" => (int)$settings['withdraw_count_max'],
            "status" => toBool($settings['withdraw_count_status'])
        ],
        "redeemButtonText" => [
            "text" => $settings['redeem_button_text'],
            "status" => toBool($settings['redeem_button_status'])
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// -----------------------------
// 2️⃣ POST/PUT METHOD - Update data
// -----------------------------
elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
        exit;
    }

    // Extract and validate fields
    $withdrawMax = isset($data['withdrawLimit']['max']) ? (int)$data['withdrawLimit']['max'] : 20;
    $withdrawStatus = toEnum($data['withdrawLimit']['status'] ?? true);

    $withdrawCountMax = isset($data['numberOfWithdraws']['max']) ? (int)$data['numberOfWithdraws']['max'] : 3;
    $withdrawCountStatus = toEnum($data['numberOfWithdraws']['status'] ?? true);

    $redeemText = $data['redeemButtonText']['text'] ?? 'Redeem';
    $redeemStatus = toEnum($data['redeemButtonText']['status'] ?? true);

    // Update query (assuming single record table)
    $sql = "UPDATE withdraw_settings 
            SET 
                withdraw_max = ?, 
                withdraw_status = ?, 
                withdraw_count_max = ?, 
                withdraw_count_status = ?, 
                redeem_button_text = ?, 
                redeem_button_status = ?
            WHERE id = 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "isisss", 
        $withdrawMax, 
        $withdrawStatus, 
        $withdrawCountMax, 
        $withdrawCountStatus, 
        $redeemText, 
        $redeemStatus
    );

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Withdraw settings updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update settings"]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// -----------------------------
// 3️⃣ Unsupported Method
// -----------------------------
else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}
?>
