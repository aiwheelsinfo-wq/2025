<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

try {
    if (!$conn) {
        throw new Exception("Database connection failed.");
    }

    // Support both JSON body and POST form data
    $rawInput = file_get_contents('php://input');
    $jsonData = json_decode($rawInput, true);

    $service_key = $jsonData['service_key'] ?? $_POST['service_key'] ?? null;
    $is_enabled = null;

    if (isset($jsonData['is_enabled'])) {
        $is_enabled = ($jsonData['is_enabled'] === true || $jsonData['is_enabled'] === 1 || $jsonData['is_enabled'] === '1' || $jsonData['is_enabled'] === 'true') ? 1 : 0;
    } elseif (isset($_POST['is_enabled'])) {
        $is_enabled = ($_POST['is_enabled'] === '1' || $_POST['is_enabled'] === 'true' || $_POST['is_enabled'] === 1 || $_POST['is_enabled'] === true) ? 1 : 0;
    }

    $coming_soon_message = $jsonData['coming_soon_message'] ?? $_POST['coming_soon_message'] ?? null;
    $coming_soon_title = $jsonData['coming_soon_title'] ?? $_POST['coming_soon_title'] ?? null;
    $badge_text = $jsonData['badge_text'] ?? $_POST['badge_text'] ?? null;

    if (empty($service_key)) {
        throw new Exception("Missing required parameter: service_key");
    }

    // Allowed service keys
    $valid_keys = ['one_way', 'round_trip', 'local_duty', 'local_taxi'];
    if (!in_array(strtolower($service_key), $valid_keys)) {
        throw new Exception("Invalid service_key: $service_key");
    }

    $updates = [];
    $types = '';
    $params = [];

    if ($is_enabled !== null) {
        $updates[] = "is_enabled = ?";
        $types .= 'i';
        $params[] = $is_enabled;
    }

    if ($coming_soon_message !== null) {
        $updates[] = "coming_soon_message = ?";
        $types .= 's';
        $params[] = trim($coming_soon_message);
    }

    if ($coming_soon_title !== null) {
        $updates[] = "coming_soon_title = ?";
        $types .= 's';
        $params[] = trim($coming_soon_title);
    }

    if ($badge_text !== null) {
        $updates[] = "badge_text = ?";
        $types .= 's';
        $params[] = trim($badge_text);
    }

    if (empty($updates)) {
        throw new Exception("No fields to update.");
    }

    $types .= 's';
    $params[] = $service_key;

    $sql = "UPDATE trip_service_status SET " . implode(", ", $updates) . " WHERE service_key = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();

    // Fetch updated row
    $fetchStmt = $conn->prepare("SELECT service_key, service_name, is_enabled, badge_text, coming_soon_title, coming_soon_message FROM trip_service_status WHERE service_key = ?");
    $fetchStmt->bind_param("s", $service_key);
    $fetchStmt->execute();
    $result = $fetchStmt->get_result();
    $row = $result->fetch_assoc();
    $fetchStmt->close();

    echo json_encode([
        'success' => true,
        'message' => "Service status for " . ($row['service_name'] ?? $service_key) . " updated successfully.",
        'data' => [
            'service_key' => $row['service_key'],
            'service_name' => $row['service_name'],
            'is_enabled' => (intval($row['is_enabled']) === 1),
            'badge_text' => $row['badge_text'],
            'coming_soon_title' => $row['coming_soon_title'],
            'coming_soon_message' => $row['coming_soon_message']
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
