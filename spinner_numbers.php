<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';

// Handle CORS preflight (for PUT/POST)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Helper: Convert ENUM string to boolean
function toBool($val) {
    return strtolower(trim($val)) === 'true';
}

// Helper: Convert boolean to ENUM string
function toEnum($val) {
    return $val ? 'true' : 'false';
}

// -----------------------------
// 1️⃣ GET METHOD — Fetch Settings
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $query = $conn->query("SELECT * FROM spinner_wheel_settings LIMIT 1");

    if (!$query || $query->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Spinner settings not found"]);
        exit;
    }

    $settings = $query->fetch_assoc();
    $conn->close();

    // Convert spinner values from comma-separated string → array
    $spinnerData = array_map('intval', explode(',', $settings['spinner_values']));

    // Build structured response
    $response = [
        "numberOfSpinsText" => [
            "text" => $settings['spins_text'],
            "status" => toBool($settings['spins_status'])
        ],
        "spinnerStatus" => toBool($settings['spinnerStatus']),
        "winLimit" => [
            "min" => (int)$settings['win_min'],
            "max" => (int)$settings['win_max']
        ],
        "spinnerWheel" => $spinnerData,
        "withdrawText" => [
            "text" => $settings['withdraw_text'],
            "status" => toBool($settings['withdraw_status'])
        ]
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// -----------------------------
// 2️⃣ POST or PUT METHOD — Update Settings
// -----------------------------
elseif (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'])) {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!$data) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
        exit;
    }

    // Extract and sanitize values
    $spinsText = $data['numberOfSpinsText']['text'] ?? 'Spins';
    $spinsStatus = toEnum($data['numberOfSpinsText']['status'] ?? true);

    $spinnerStatus = toEnum($data['spinnerStatus'] ?? true);

    $winMin = isset($data['winLimit']['min']) ? (int)$data['winLimit']['min'] : 2;
    $winMax = isset($data['winLimit']['max']) ? (int)$data['winLimit']['max'] : 9;

    $spinnerWheel = $data['spinnerWheel'] ?? [1,2,3,4,5,6,7,8,0];
    $spinnerValues = implode(',', array_map('intval', $spinnerWheel));

    $withdrawText = $data['withdrawText']['text'] ?? 'Withdraw';
    $withdrawStatus = toEnum($data['withdrawText']['status'] ?? true);

    // Update record (assuming single record table)
    $sql = "UPDATE spinner_wheel_settings 
            SET spins_text = ?, spins_status = ?, spinnerStatus = ?, 
                win_min = ?, win_max = ?, spinner_values = ?, 
                withdraw_text = ?, withdraw_status = ? 
            WHERE id = 1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssissss",
        $spinsText,
        $spinsStatus,
        $spinnerStatus,
        $winMin,
        $winMax,
        $spinnerValues,
        $withdrawText,
        $withdrawStatus
    );

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Spinner settings updated successfully"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update spinner settings"]);
    }

    $stmt->close();
    $conn->close();
    exit;
}

// -----------------------------
// 3️⃣ Invalid Method
// -----------------------------
else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}
?>
