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

$rawInput = file_get_contents("php://input");
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    $input = $_POST;
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
$ticket_no = trim($input['ticket_no'] ?? '');
$status = trim($input['status'] ?? '');
$admin_action = trim($input['admin_action'] ?? '');
$admin_notes = trim($input['admin_notes'] ?? '');
$block_driver = !empty($input['block_driver']) && ($input['block_driver'] === true || $input['block_driver'] === '1' || $input['block_driver'] === 1 || $input['block_driver'] === 'true');

if ($id <= 0 && empty($ticket_no)) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Incident ID or Ticket Number is required"
    ]);
    exit;
}

// Fetch existing report
$query = "SELECT * FROM trip_incident_reports WHERE id = ? OR ticket_no = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("is", $id, $ticket_no);
$stmt->execute();
$res = $stmt->get_result();
$report = $res->fetch_assoc();
$stmt->close();

if (!$report) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Incident report not found"
    ]);
    exit;
}

$report_id = $report['id'];
$driver_id = $report['driver_id'];

// Determine status & resolved_at
$resolved_at_sql = "resolved_at";
if ($status === 'Resolved' || $status === 'Dismissed') {
    $resolved_at_sql = "NOW()";
} else if ($status === 'Pending' || $status === 'Investigating') {
    $resolved_at_sql = "NULL";
}

$update_sql = "UPDATE trip_incident_reports 
    SET status = COALESCE(NULLIF(?, ''), status),
        admin_action = COALESCE(NULLIF(?, ''), admin_action),
        admin_notes = COALESCE(NULLIF(?, ''), admin_notes),
        resolved_at = $resolved_at_sql
    WHERE id = ?";

$upd_stmt = $conn->prepare($update_sql);
$upd_stmt->bind_param("sssi", $status, $admin_action, $admin_notes, $report_id);
$success = $upd_stmt->execute();
$upd_stmt->close();

if (!$success) {
    echo json_encode([
        "status" => "error",
        "success" => false,
        "message" => "Failed to update incident: " . $conn->error
    ]);
    exit;
}

$driver_blocked = false;
// If admin requested driver blocking, update drivers table
if ($block_driver && !empty($driver_id)) {
    $block_reason = "Blocked due to Incident " . $report['ticket_no'] . ": " . ($admin_action ?: $report['incident_type']);
    $block_stmt = $conn->prepare("UPDATE drivers SET status = 'inactive', block_reason = ?, blocked_at = NOW() WHERE driver_id = ? OR phone_number = ?");
    if ($block_stmt) {
        $block_stmt->bind_param("sss", $block_reason, $driver_id, $driver_id);
        if ($block_stmt->execute()) {
            $driver_blocked = true;
        }
        $block_stmt->close();
    }
}

echo json_encode([
    "status" => "success",
    "success" => true,
    "message" => "Incident report updated successfully" . ($driver_blocked ? " and driver was blocked." : "."),
    "driver_blocked" => $driver_blocked,
    "data" => [
        "id" => $report_id,
        "ticket_no" => $report['ticket_no'],
        "status" => $status ?: $report['status'],
        "admin_action" => $admin_action ?: $report['admin_action'],
        "admin_notes" => $admin_notes ?: $report['admin_notes']
    ]
]);
