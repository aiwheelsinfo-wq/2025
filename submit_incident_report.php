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

// Parse request data (JSON or Form POST)
$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

$booking_id = trim($input['booking_id'] ?? '');
$customer_phone = trim($input['customer_phone'] ?? $input['phone_number'] ?? $input['mobile'] ?? '');
$driver_id = trim($input['driver_id'] ?? '');
$driver_name = trim($input['driver_name'] ?? '');
$incident_type = trim($input['incident_type'] ?? 'Other');
$severity = trim($input['severity'] ?? 'Medium');
$description = trim($input['description'] ?? $input['details'] ?? '');

// Validation
if (empty($booking_id)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Booking ID is required."
    ]);
    exit;
}

if (empty($description)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Please provide details describing the incident."
    ]);
    exit;
}

// Ensure valid severity
$valid_severities = ['Critical', 'High', 'Medium', 'Low'];
if (!in_array($severity, $valid_severities)) {
    $severity = 'Medium';
}

// Lookup booking record to find canonical booking info, driver info, and customer phone if missing
$canonical_booking_id = $booking_id;
$stmt = $conn->prepare("SELECT id, booking_id, customer_number, driver_id FROM bookings WHERE id = ? OR booking_id = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param("ss", $booking_id, $booking_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
        $canonical_booking_id = !empty($row['booking_id']) ? $row['booking_id'] : (string)$row['id'];
        if (empty($driver_id) && !empty($row['driver_id'])) {
            $driver_id = (string)$row['driver_id'];
        }
        if (empty($customer_phone) && !empty($row['customer_number'])) {
            $customer_phone = $row['customer_number'];
        }
    }
    $stmt->close();
}

// If driver_name still empty but driver_id exists, lookup from drivers table
if (!empty($driver_id) && empty($driver_name)) {
    $d_stmt = $conn->prepare("SELECT full_name FROM drivers WHERE driver_id = ? OR phone_number = ? LIMIT 1");
    if ($d_stmt) {
        $d_stmt->bind_param("ss", $driver_id, $driver_id);
        $d_stmt->execute();
        $d_res = $d_stmt->get_result();
        if ($d_row = $d_res->fetch_assoc()) {
            $driver_name = $d_row['full_name'];
        }
        $d_stmt->close();
    }
}

if (empty($customer_phone)) {
    $customer_phone = "Customer";
}

// Generate unique ticket number
$rand_suffix = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 5));
$ticket_no = "INC-" . date('ymd') . "-" . $rand_suffix;

// Insert incident report
$ins_stmt = $conn->prepare("INSERT INTO trip_incident_reports 
    (ticket_no, booking_id, customer_phone, driver_id, driver_name, incident_type, severity, description, status) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

if (!$ins_stmt) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Database statement preparation failed: " . $conn->error
    ]);
    exit;
}

$ins_stmt->bind_param("ssssssss", $ticket_no, $canonical_booking_id, $customer_phone, $driver_id, $driver_name, $incident_type, $severity, $description);

if ($ins_stmt->execute()) {
    $report_id = $ins_stmt->insert_id;
    $ins_stmt->close();

    echo json_encode([
        "status" => "success",
        "success" => true,
        "message" => "Incident reported successfully. Rentox Safety Team has been notified.",
        "ticket_no" => $ticket_no,
        "data" => [
            "id" => $report_id,
            "ticket_no" => $ticket_no,
            "booking_id" => $canonical_booking_id,
            "driver_name" => $driver_name,
            "incident_type" => $incident_type,
            "severity" => $severity,
            "status" => "Pending",
            "created_at" => date('Y-m-d H:i:s')
        ]
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Failed to submit report: " . $conn->error
    ]);
}
