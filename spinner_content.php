<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

include 'db_connect.php';

// Handle CORS preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Helper: Convert ENUM string <-> boolean
function toBool($val) {
    return strtolower(trim($val)) === 'true';
}
function toEnum($val) {
    return $val ? 'true' : 'false';
}

// -----------------------------
// GET METHOD
// -----------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Check if ?query=spinner
    $queryParam = $_GET['query'] ?? '';

    $spinnerQuery = $conn->query("SELECT * FROM spinner_settings LIMIT 1");
    if (!$spinnerQuery || $spinnerQuery->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Spinner settings not found"]);
        exit;
    }
    $settings = $spinnerQuery->fetch_assoc();

    $spinner_texts = [];
    for ($i = 1; $i <= 4; $i++) {
        $spinner_texts[] = [
            "text" => $settings["text$i"] ?? '',
            "status" => toBool($settings["status$i"] ?? 'false')
        ];
    }

    // 1️⃣ Only spinner settings
    if ($queryParam === 'spinner') {
        $response = [
            "spinnerCardStatus" => toBool($settings['spinnerCardStatus']),
            "title" => $settings['title'],
            "titleStatus" => toBool($settings['titleStatus']),
            "subtitle_template" => $settings['subtitle_template'],
            "subtitleStatus" => toBool($settings['subtitleStatus']),
            "texts" => $spinner_texts
        ];

        echo json_encode(["status" => "success", "data" => $response], JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }

    // 2️⃣ Spinner + user reward/spin data
    $phone_number = $_GET['phone_number'] ?? '';
    if (empty($phone_number) || !preg_match('/^\d{10}$/', $phone_number)) {
        echo json_encode(["status" => "error", "message" => "Invalid phone number"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT reward_point, available_spin FROM users WHERE phone_number = ?");
    $stmt->bind_param("s", $phone_number);
    $stmt->execute();
    $result = $stmt->get_result();

    $reward_point = 0;
    $available_spin = 0;
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $reward_point = (int)$row['reward_point'];
        $available_spin = (int)$row['available_spin'];
    }
    $stmt->close();
    $conn->close();

    $subtitle = str_replace("{available_spin}", $available_spin, $settings['subtitle_template']);

    $response = [
        "spinnerCardStatus" => toBool($settings['spinnerCardStatus']),
        "title" => $settings['title'],
        "titleStatus" => toBool($settings['titleStatus']),
        "subtitle" => $subtitle,
        "subtitleStatus" => toBool($settings['subtitleStatus']),
        "texts" => $spinner_texts,
        "reward_point" => $reward_point,
        "available_spin" => $available_spin
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);
    exit;
}

// -----------------------------
// POST/PUT handled below
// -----------------------------
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(["status" => "error", "message" => "POST not implemented"]);
    exit;
}
elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) {
        echo json_encode(["status" => "error", "message" => "Invalid JSON data"]);
        exit;
    }

    // Extract fields safely
    $spinnerCardStatus = toEnum($data['spinnerCardStatus'] ?? true);
    $title = $data['title'] ?? 'Get your Reward Now!';
    $titleStatus = toEnum($data['titleStatus'] ?? true);
    $subtitle_template = $data['subtitle_template'] ?? 'You have {available_spin} spins available!';
    $subtitleStatus = toEnum($data['subtitleStatus'] ?? false);

    $text1 = $data['texts'][0]['text'] ?? '';
    $status1 = toEnum($data['texts'][0]['status'] ?? false);
    $text2 = $data['texts'][1]['text'] ?? '';
    $status2 = toEnum($data['texts'][1]['status'] ?? false);
    $text3 = $data['texts'][2]['text'] ?? '';
    $status3 = toEnum($data['texts'][2]['status'] ?? false);
    $text4 = $data['texts'][3]['text'] ?? '';
    $status4 = toEnum($data['texts'][3]['status'] ?? false);

    $sql = "UPDATE spinner_settings SET 
                spinnerCardStatus=?, 
                title=?, 
                titleStatus=?, 
                subtitle_template=?, 
                subtitleStatus=?, 
                text1=?, status1=?, 
                text2=?, status2=?, 
                text3=?, status3=?, 
                text4=?, status4=? 
            WHERE id=1";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "sssssssssssss",
        $spinnerCardStatus,
        $title,
        $titleStatus,
        $subtitle_template,
        $subtitleStatus,
        $text1, $status1,
        $text2, $status2,
        $text3, $status3,
        $text4, $status4
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
// Invalid Method
// -----------------------------
else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit;
}
?>
