<?php
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../2025/db_connect.php';
date_default_timezone_set('Asia/Kolkata');

$phone_number = $_POST['phone_number'] ?? $_POST['driver_id'] ?? $_GET['phone_number'] ?? $_GET['driver_id'] ?? '';

if (empty($phone_number)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing required parameter: phone_number'
    ]);
    exit;
}

// GET: Fetch current online/offline status
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $conn->prepare("SELECT status, block_reason FROM drivers WHERE phone_number = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("s", $phone_number);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $driver_status = trim($row['status'] ?? 'active');
            if (strtolower($driver_status) === 'blocked') {
                echo json_encode([
                    'status' => 'blocked',
                    'is_online' => false,
                    'is_blocked' => true,
                    'driver_status' => 'blocked',
                    'block_reason' => $row['block_reason'] ?? 'Administrative restriction',
                    'message' => 'Partner account is blocked. Reason: ' . ($row['block_reason'] ?? 'Administrative restriction')
                ]);
                $stmt->close();
                exit;
            }
            $is_online = ($driver_status === 'active');
            echo json_encode([
                'status' => 'success',
                'is_online' => $is_online,
                'is_blocked' => false,
                'driver_status' => $driver_status
            ]);
            $stmt->close();
            exit;
        }
        $stmt->close();
    }
    // Default to active if driver not found or status not set
    echo json_encode([
        'status' => 'success',
        'is_online' => true,
        'is_blocked' => false,
        'driver_status' => 'active'
    ]);
    exit;
}

// POST: Update status ('active' for online, 'offline' for offline)
// Check if currently blocked in database
$chk = $conn->prepare("SELECT status, block_reason FROM drivers WHERE phone_number = ? LIMIT 1");
if ($chk) {
    $chk->bind_param("s", $phone_number);
    $chk->execute();
    $res = $chk->get_result();
    if ($r = $res->fetch_assoc()) {
        if (strtolower(trim($r['status'] ?? '')) === 'blocked') {
            $chk->close();
            echo json_encode([
                'status' => 'blocked',
                'is_online' => false,
                'is_blocked' => true,
                'driver_status' => 'blocked',
                'block_reason' => $r['block_reason'] ?? 'Administrative restriction',
                'message' => 'Account is blocked. You cannot go online. Reason: ' . ($r['block_reason'] ?? 'Administrative restriction')
            ]);
            exit;
        }
    }
    $chk->close();
}

$new_status = trim($_POST['status'] ?? '');
if (empty($new_status)) {
    // If passed as boolean is_online
    if (isset($_POST['is_online'])) {
        $val = $_POST['is_online'];
        $new_status = ($val === 'true' || $val === '1' || $val === true) ? 'active' : 'offline';
    } else {
        $new_status = 'active';
    }
}

// Normalize: only 'active' or 'offline' allowed
if ($new_status !== 'active' && $new_status !== 'offline') {
    $new_status = ($new_status === 'inactive' || $new_status === 'off') ? 'offline' : 'active';
}

$stmt = $conn->prepare("UPDATE drivers SET status = ? WHERE phone_number = ?");
if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => 'DB prepare error: ' . $conn->error
    ]);
    exit;
}

$stmt->bind_param("ss", $new_status, $phone_number);
$success = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

echo json_encode([
    'status' => 'success',
    'message' => 'Driver status updated to ' . $new_status,
    'is_online' => ($new_status === 'active'),
    'driver_status' => $new_status
]);
?>
