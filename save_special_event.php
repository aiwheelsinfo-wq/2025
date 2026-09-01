<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

$action = $data['action'] ?? 'save';

if ($action === 'delete') {
    $id = (int)($data['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("DELETE FROM `one_way_special_events` WHERE `id` = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Event deleted']);
        exit;
    }
} elseif ($action === 'toggle') {
    $id = (int)($data['id'] ?? 0);
    $isActive = !empty($data['isActive']) ? 1 : 0;
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE `one_way_special_events` SET `is_active` = ? WHERE `id` = ?");
        $stmt->bind_param("ii", $isActive, $id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(['status' => 'success', 'message' => 'Event status toggled']);
        exit;
    }
} else {
    // Save new event
    $name = trim($data['name'] ?? '');
    $startDate = $data['startDate'] ?? '';
    $endDate = !empty($data['endDate']) ? $data['endDate'] : $startDate;
    $multiplier = (float)($data['multiplier'] ?? 1.25);
    $reason = $data['reason'] ?? '';
    $category = $data['category'] ?? 'Custom Event';
    $isActive = 1;
    $surgePct = '+' . round(($multiplier - 1.0) * 100) . '%';

    if (empty($name) || empty($startDate)) {
        echo json_encode(['status' => 'error', 'message' => 'Name and start date are required']);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO `one_way_special_events` (`event_name`, `start_date`, `end_date`, `multiplier`, `surge_pct_label`, `reason`, `category`, `is_active`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssdsssi", $name, $startDate, $endDate, $multiplier, $surgePct, $reason, $category, $isActive);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();

    echo json_encode([
        'status' => 'success',
        'message' => 'Event saved successfully',
        'event' => [
            'id' => $newId,
            'name' => $name,
            'startDate' => $startDate,
            'endDate' => $endDate,
            'multiplier' => $multiplier,
            'surgePct' => $surgePct,
            'reason' => $reason,
            'category' => $category,
            'isActive' => true
        ]
    ]);
    exit;
}
