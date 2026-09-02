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
require_once __DIR__ . '/LocalTaxiFareCalculator.php';

// 1. Global Settings
$global = LocalTaxiFareCalculator::getGlobalSettings($conn);

// 2. Vehicle Rules
$vehRes = mysqli_query($conn, "SELECT * FROM `local_taxi_vehicle_rules` ORDER BY `display_order` ASC, `id` ASC");
$vehicles = [];

if ($vehRes) {
    while ($row = mysqli_fetch_assoc($vehRes)) {
        $label = $row['car_type_label'];
        $vehicles[$label] = [
            'id' => (int)$row['id'],
            'car_type_id' => (int)$row['car_type_id'],
            'car_type_label' => $row['car_type_label'],
            'base_fare' => (float)$row['base_fare'],
            'included_base_km' => (float)$row['included_base_km'],
            'per_km_rate' => (float)$row['per_km_rate'],
            'waiting_charge_per_min' => (float)$row['waiting_charge_per_min'],
            'min_floor_rate' => (float)$row['min_floor_rate'],
            'max_ceiling_rate' => (float)$row['max_ceiling_rate'],
            'is_active' => (bool)$row['is_active'],
            'display_order' => (int)$row['display_order']
        ];
    }
}

// 3. Live Demand Telemetry
$todayRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `bookings` WHERE LOWER(`trip_type`) LIKE '%local%' AND `date` = CURDATE()");
$todayDemand = ($todayRes && $r = mysqli_fetch_assoc($todayRes)) ? (int)$r['cnt'] : 0;

echo json_encode([
    'status' => 'success',
    'global_settings' => [
        'dynamic_pricing_active' => (bool)$global['dynamic_pricing_active'],
        'pricing_sensitivity' => (float)$global['pricing_sensitivity'],
        'peak_surge_active' => (bool)$global['peak_surge_active'],
        'peak_morning_start' => $global['peak_morning_start'] ?? '08:00',
        'peak_morning_end' => $global['peak_morning_end'] ?? '11:00',
        'peak_evening_start' => $global['peak_evening_start'] ?? '17:30',
        'peak_evening_end' => $global['peak_evening_end'] ?? '21:00',
        'peak_multiplier' => (float)$global['peak_multiplier'],
        'night_surcharge_active' => (bool)$global['night_surcharge_active'],
        'night_start' => $global['night_start'] ?? '23:00',
        'night_end' => $global['night_end'] ?? '05:00',
        'night_multiplier' => (float)$global['night_multiplier'],
        'gst_active' => (bool)$global['gst_active'],
        'gst_rate' => (float)$global['gst_rate'],
        'company_share_active' => (bool)$global['company_share_active'],
        'company_share_type' => $global['company_share_type'] ?? 'percent',
        'company_share_value' => (float)$global['company_share_value'],
        'today_demand' => $todayDemand,
        'reference_demand' => 8.0
    ],
    'vehicles' => $vehicles
], JSON_PRETTY_PRINT);
