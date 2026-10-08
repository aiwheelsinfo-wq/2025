<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/db_connect.php';

if (!$conn) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database connection failed"
    ]);
    exit;
}

$booking_id = trim($_GET['booking_id'] ?? $_POST['booking_id'] ?? '');

if (empty($booking_id)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Booking ID is required"
    ]);
    exit;
}

// Check for reports associated with this booking
$stmt = $conn->prepare("SELECT id, ticket_no, booking_id, customer_phone, driver_id, driver_name, incident_type, severity, description, status, admin_action, admin_notes, created_at, resolved_at FROM trip_incident_reports WHERE booking_id = ? ORDER BY id DESC");

if (!$stmt) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Prepare failed: " . $conn->error
    ]);
    exit;
}

$stmt->bind_param("s", $booking_id);
$stmt->execute();
$res = $stmt->get_result();

$reports = [];
while ($row = $res->fetch_assoc()) {
    $reports[] = $row;
}
$stmt->close();

echo json_encode([
    "status" => "success",
    "success" => true,
    "has_reported" => count($reports) > 0,
    "reports" => $reports,
    "latest_report" => count($reports) > 0 ? $reports[0] : null
]);
