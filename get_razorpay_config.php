<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/razorpay_config.php';

echo json_encode([
    "success" => true,
    "razorpay_key" => RAZORPAY_ACTIVE_KEY,
    "is_live" => RAZORPAY_USE_LIVE
]);
?>
