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

$status = trim($_GET['status'] ?? 'all');
$severity = trim($_GET['severity'] ?? 'all');
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(100, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// 1. Calculate KPI Metrics across all records
$kpi_query = "SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN status = 'Investigating' THEN 1 ELSE 0 END) AS investigating_count,
    SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) AS resolved_count,
    SUM(CASE WHEN severity = 'Critical' AND status != 'Resolved' AND status != 'Dismissed' THEN 1 ELSE 0 END) AS critical_count
FROM trip_incident_reports";

$kpi_res = $conn->query($kpi_query);
$kpi = [
    "total" => 0,
    "pending" => 0,
    "investigating" => 0,
    "resolved" => 0,
    "critical" => 0
];
if ($kpi_res && $row = $kpi_res->fetch_assoc()) {
    $kpi["total"] = (int)$row['total'];
    $kpi["pending"] = (int)$row['pending_count'];
    $kpi["investigating"] = (int)$row['investigating_count'];
    $kpi["resolved"] = (int)$row['resolved_count'];
    $kpi["critical"] = (int)$row['critical_count'];
}

// 2. Build Filtered Query
$where_clauses = [];
$params = [];
$types = "";

if (!empty($status) && $status !== 'all') {
    $where_clauses[] = "r.status = ?";
    $params[] = $status;
    $types .= "s";
}

if (!empty($severity) && $severity !== 'all') {
    $where_clauses[] = "r.severity = ?";
    $params[] = $severity;
    $types .= "s";
}

if (!empty($search)) {
    $search_param = "%" . $search . "%";
    $where_clauses[] = "(r.ticket_no LIKE ? OR r.booking_id LIKE ? OR r.customer_phone LIKE ? OR r.driver_name LIKE ? OR r.incident_type LIKE ? OR r.description LIKE ?)";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_param;
        $types .= "s";
    }
}

$where_sql = "";
if (count($where_clauses) > 0) {
    $where_sql = "WHERE " . implode(" AND ", $where_clauses);
}

// Count filtered
$count_sql = "SELECT COUNT(*) as filtered_count FROM trip_incident_reports r $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types && count($params) > 0) {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$filtered_count = 0;
if ($c_res = $count_stmt->get_result()->fetch_assoc()) {
    $filtered_count = (int)$c_res['filtered_count'];
}
$count_stmt->close();

// Fetch records with booking details & driver details join
$fetch_sql = "SELECT 
    r.id,
    r.ticket_no,
    r.booking_id,
    r.customer_phone,
    r.driver_id,
    r.driver_name,
    r.incident_type,
    r.severity,
    r.description,
    r.status,
    r.admin_action,
    r.admin_notes,
    r.created_at,
    r.resolved_at,
    b.from_address,
    b.to_address,
    b.trip_type,
    b.car_type,
    b.booking_status,
    d.phone_number AS driver_phone,
    d.status AS driver_current_status
FROM trip_incident_reports r
LEFT JOIN bookings b ON (b.id = r.booking_id OR b.booking_id = r.booking_id)
LEFT JOIN drivers d ON (d.driver_id = r.driver_id OR d.phone_number = r.driver_id)
$where_sql
ORDER BY r.id DESC
LIMIT ? OFFSET ?";

$fetch_stmt = $conn->prepare($fetch_sql);
$full_types = $types . "ii";
$full_params = array_merge($params, [$limit, $offset]);

$fetch_stmt->bind_param($full_types, ...$full_params);
$fetch_stmt->execute();
$res = $fetch_stmt->get_result();

$reports = [];
while ($row = $res->fetch_assoc()) {
    $reports[] = $row;
}
$fetch_stmt->close();

echo json_encode([
    "status" => "success",
    "success" => true,
    "kpi" => $kpi,
    "total" => $filtered_count,
    "page" => $page,
    "limit" => $limit,
    "reports" => $reports
]);
