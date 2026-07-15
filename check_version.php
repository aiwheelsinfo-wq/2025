<?php
header('Content-Type: application/json');

// Define minimum required version numbers.
// Change these when you want to force updates on the Play Store.
$min_customer_version = "1.0.0";
$min_driver_version = "1.0.0";

$app_type = isset($_GET['app_type']) ? $_GET['app_type'] : 'customer'; // 'customer' or 'driver'
$app_version = isset($_GET['version']) ? $_GET['version'] : '1.0.0';

$force_update = false;

if ($app_type === 'driver') {
    if (version_compare($app_version, $min_driver_version, '<')) {
        $force_update = true;
    }
} else {
    if (version_compare($app_version, $min_customer_version, '<')) {
        $force_update = true;
    }
}

echo json_encode([
    "success" => true,
    "force_update" => $force_update,
    "customer_min" => $min_customer_version,
    "driver_min" => $min_driver_version,
    "play_store_url" => $app_type === 'driver' 
        ? "https://play.google.com/store/apps/details?id=com.rentox.driver" 
        : "https://play.google.com/store/apps/details?id=com.agni_car_rental"
]);
?>
