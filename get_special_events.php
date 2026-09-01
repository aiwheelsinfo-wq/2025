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

$res = mysqli_query($conn, "SELECT * FROM `one_way_special_events` ORDER BY `start_date` ASC");
$events = [];

if ($res) {
    while ($row = mysqli_fetch_assoc($res)) {
        $events[] = [
            'id' => (int)$row['id'],
            'name' => $row['event_name'],
            'startDate' => $row['start_date'],
            'endDate' => $row['end_date'],
            'multiplier' => (float)$row['multiplier'],
            'surgePct' => $row['surge_pct_label'] ?? ('+' . round(((float)$row['multiplier'] - 1.0) * 100) . '%'),
            'reason' => $row['reason'],
            'category' => $row['category'],
            'isActive' => (bool)$row['is_active']
        ];
    }
}

echo json_encode([
    'status' => 'success',
    'events' => $events
], JSON_PRETTY_PRINT);
