<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../admin2025/db_connect.php';

try {
    $minAdvanceHours = 5.0;
    $minWalletBalance = 1000.0;
    
    $res = mysqli_query($conn, "SELECT min_advance_booking_hours, min_wallet_balance FROM one_way_global_settings WHERE id = 1 LIMIT 1");
    if ($res && $row = mysqli_fetch_assoc($res)) {
        if (isset($row['min_advance_booking_hours']) && $row['min_advance_booking_hours'] !== null) {
            $minAdvanceHours = (float)$row['min_advance_booking_hours'];
        }
        if (isset($row['min_wallet_balance']) && (float)$row['min_wallet_balance'] > 0) {
            $minWalletBalance = (float)$row['min_wallet_balance'];
        }
    }

    echo json_encode([
        'success' => true,
        'status' => 'success',
        'min_advance_booking_hours' => $minAdvanceHours,
        'min_wallet_balance' => $minWalletBalance
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'min_advance_booking_hours' => 5.0,
        'min_wallet_balance' => 1000.0,
        'message' => $e->getMessage()
    ]);
}
if (isset($conn) && $conn) {
    mysqli_close($conn);
}
