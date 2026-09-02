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

// 3. Live Demand & Supply Telemetry
$todayDemandRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `bookings` WHERE (LOWER(`trip_type`) LIKE '%local%' OR `trip_type` = 'Local Taxi') AND `date` = CURDATE()");
$todayDemand = ($todayDemandRes && $r = mysqli_fetch_assoc($todayDemandRes)) ? (int)$r['cnt'] : 0;

$driverRes = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM `drivers` WHERE `status` = 'approved' OR `status` = 'active'");
$activeDrivers = ($driverRes && $rd = mysqli_fetch_assoc($driverRes)) ? (int)$rd['cnt'] : 12;
if ($activeDrivers <= 0) $activeDrivers = 8;

$refDemand = 8.0;
$demandRatio = ($todayDemand > 0) ? round($todayDemand / $refDemand, 2) : 1.0;

// Dynamic surge calculation
$currTime = date('H:i:s');
$isPeak = false;
$isNight = false;
$surgeMultiplier = 1.0;
$surgeLabel = 'Balanced Demand (1.0x)';

if (!empty($global['peak_surge_active'])) {
    $mStart = $global['peak_morning_start'] ?? '08:00:00';
    $mEnd = $global['peak_morning_end'] ?? '11:00:00';
    $eStart = $global['peak_evening_start'] ?? '17:30:00';
    $eEnd = $global['peak_evening_end'] ?? '21:00:00';

    if (($currTime >= $mStart && $currTime <= $mEnd) || ($currTime >= $eStart && $currTime <= $eEnd)) {
        $isPeak = true;
        $surgeMultiplier = (float)($global['peak_multiplier'] ?? 1.25);
        $surgeLabel = 'Peak City Rush Active (+'.round(($surgeMultiplier - 1.0)*100).'%)';
    }
}

if (!empty($global['night_surcharge_active'])) {
    $nStart = $global['night_start'] ?? '23:00:00';
    $nEnd = $global['night_end'] ?? '05:00:00';
    if ($currTime >= $nStart || $currTime <= $nEnd) {
        $isNight = true;
        $surgeMultiplier = (float)($global['night_multiplier'] ?? 1.20);
        $surgeLabel = 'Night Surcharge Active (+'.round(($surgeMultiplier - 1.0)*100).'%)';
    }
}

// 4. City Hotspot Zones Telemetry
$hotspotsRes = mysqli_query($conn, "SELECT `from_address`, COUNT(*) as cnt FROM `bookings` WHERE `from_address` IS NOT NULL AND `from_address` != '' GROUP BY `from_address` ORDER BY cnt DESC LIMIT 4");
$hotspots = [];

if ($hotspotsRes && mysqli_num_rows($hotspotsRes) > 0) {
    while ($h = mysqli_fetch_assoc($hotspotsRes)) {
        $cleanName = explode(',', $h['from_address'])[0];
        $count = (int)$h['cnt'];
        $hRatio = round($count / 4.0, 1);
        $hotspots[] = [
            'zone' => $cleanName,
            'full_address' => $h['from_address'],
            'demand_count' => $count,
            'active_cars' => max(2, (int)round($count * 0.7)),
            'demand_ratio' => $hRatio,
            'surge_multiplier' => ($hRatio >= 1.5) ? 1.35 : (($hRatio >= 1.0) ? 1.15 : 1.0),
            'status' => ($hRatio >= 1.5) ? 'High Surge' : (($hRatio >= 1.0) ? 'Moderate' : 'Normal')
        ];
    }
}

// Fallback high-density city zones if fresh database
if (empty($hotspots)) {
    $hotspots = [
        ['zone' => 'Pune Central (Shivajinagar / Swargate)', 'demand_count' => 14, 'active_cars' => 6, 'demand_ratio' => 2.3, 'surge_multiplier' => 1.40, 'status' => 'High Surge'],
        ['zone' => 'Hinjewadi IT Park Phase 1 & 2', 'demand_count' => 11, 'active_cars' => 5, 'demand_ratio' => 2.2, 'surge_multiplier' => 1.35, 'status' => 'High Surge'],
        ['zone' => 'Mumbai Airport / BKC Commercial Hub', 'demand_count' => 18, 'active_cars' => 8, 'demand_ratio' => 2.25, 'surge_multiplier' => 1.45, 'status' => 'High Surge'],
        ['zone' => 'Viman Nagar / Kharadi IT Hub', 'demand_count' => 8, 'active_cars' => 7, 'demand_ratio' => 1.14, 'surge_multiplier' => 1.15, 'status' => 'Moderate'],
    ];
}

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
        'active_drivers' => $activeDrivers,
        'demand_supply_ratio' => $demandRatio,
        'current_surge_multiplier' => $surgeMultiplier,
        'current_surge_label' => $surgeLabel,
        'is_peak' => $isPeak,
        'is_night' => $isNight
    ],
    'vehicles' => $vehicles,
    'hotspots' => $hotspots
], JSON_PRETTY_PRINT);
