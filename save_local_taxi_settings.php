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

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? $_POST;

if (empty($data)) {
    echo json_encode(['status' => 'error', 'message' => 'No data provided']);
    exit;
}

function formatTime($t, $default) {
    if (empty($t)) return $default;
    if (strlen($t) === 5) return $t . ':00';
    return $t;
}

// 1. Update Global Settings
if (!empty($data['global_settings'])) {
    $g = $data['global_settings'];
    $dynActive = !empty($g['dynamic_pricing_active']) ? 1 : 0;
    $sensitivity = (float)($g['pricing_sensitivity'] ?? 50.0);
    $peakActive = !empty($g['peak_surge_active']) ? 1 : 0;
    $mStart = formatTime($g['peak_morning_start'] ?? '', '08:00:00');
    $mEnd = formatTime($g['peak_morning_end'] ?? '', '11:00:00');
    $eStart = formatTime($g['peak_evening_start'] ?? '', '17:30:00');
    $eEnd = formatTime($g['peak_evening_end'] ?? '', '21:00:00');
    $pMult = (float)($g['peak_multiplier'] ?? 1.25);
    $nightActive = !empty($g['night_surcharge_active']) ? 1 : 0;
    $nStart = formatTime($g['night_start'] ?? '', '23:00:00');
    $nEnd = formatTime($g['night_end'] ?? '', '05:00:00');
    $nMult = (float)($g['night_multiplier'] ?? 1.20);
    $gstActive = !empty($g['gst_active']) ? 1 : 0;
    $gstRate = (float)($g['gst_rate'] ?? 5.0);
    $cActive = !empty($g['company_share_active']) ? 1 : 0;
    $cType = $g['company_share_type'] ?? 'percent';
    $cVal = (float)($g['company_share_value'] ?? 10.0);

    $sql = "UPDATE `local_taxi_global_settings` SET 
        `dynamic_pricing_active` = ?,
        `pricing_sensitivity` = ?,
        `peak_surge_active` = ?,
        `peak_morning_start` = ?,
        `peak_morning_end` = ?,
        `peak_evening_start` = ?,
        `peak_evening_end` = ?,
        `peak_multiplier` = ?,
        `night_surcharge_active` = ?,
        `night_start` = ?,
        `night_end` = ?,
        `night_multiplier` = ?,
        `gst_active` = ?,
        `gst_rate` = ?,
        `company_share_active` = ?,
        `company_share_type` = ?,
        `company_share_value` = ?
        WHERE `id` = 1
    ";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("idissssdissdidisd", 
            $dynActive, $sensitivity, $peakActive, $mStart, $mEnd, $eStart, $eEnd, $pMult,
            $nightActive, $nStart, $nEnd, $nMult, $gstActive, $gstRate, $cActive, $cType, $cVal
        );
        $stmt->execute();
        $stmt->close();
    }
}

// 2. Update Vehicle Rules
if (!empty($data['vehicles']) && is_array($data['vehicles'])) {
    foreach ($data['vehicles'] as $carLabel => $v) {
        $baseFare = (float)($v['base_fare'] ?? 250.0);
        $incKm = (float)($v['included_base_km'] ?? 5.0);
        $perKm = (float)($v['per_km_rate'] ?? 14.0);
        $waitMin = (float)($v['waiting_charge_per_min'] ?? 2.0);
        $minFloor = (float)($v['min_floor_rate'] ?? ($perKm * 0.8));
        $maxCeil = (float)($v['max_ceiling_rate'] ?? ($perKm * 1.5));
        $isActive = !empty($v['is_active']) ? 1 : 0;

        $stmt = $conn->prepare("UPDATE `local_taxi_vehicle_rules` SET 
            `base_fare` = ?,
            `included_base_km` = ?,
            `per_km_rate` = ?,
            `waiting_charge_per_min` = ?,
            `min_floor_rate` = ?,
            `max_ceiling_rate` = ?,
            `is_active` = ?
            WHERE `car_type_label` = ?
        ");
        if ($stmt) {
            $stmt->bind_param("ddddddis", $baseFare, $incKm, $perKm, $waitMin, $minFloor, $maxCeil, $isActive, $carLabel);
            $stmt->execute();
            $stmt->close();
        }
    }
}

echo json_encode([
    'status' => 'success',
    'message' => 'Local Taxi settings and vehicle rules updated successfully'
]);
