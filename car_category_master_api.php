<?php
/**
 * Standalone Car Category & Multi-Trip Fleet Master API (2025 Mirror)
 * 
 * Allows unified management of Car Categories and their corresponding
 * fares across One-Way, Round-Trip, and Local-Duty trips.
 * 
 * Strictly isolated: DOES NOT modify any other backend files.
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (file_exists(__DIR__ . '/db_connect.php')) {
    require_once __DIR__ . '/db_connect.php';
} elseif (file_exists(__DIR__ . '/../admin_dashboard/db_connect.php')) {
    require_once __DIR__ . '/../admin_dashboard/db_connect.php';
} elseif (file_exists('/var/www/html/admin2025/db_connect.php')) {
    require_once '/var/www/html/admin2025/db_connect.php';
} else {
    // Fallback direct connection
    $conn = mysqli_connect("localhost", "agnicar", "dGwW(W8b237~", "agnicar2025");
}

$rawPayload = file_get_contents('php://input');
$jsonPayload = json_decode($rawPayload, true) ?? [];
$params = array_merge($_GET, $_POST, $jsonPayload);

$action = $params['action'] ?? 'get_all';

function sendJson($status, $message, $extra = []) {
    echo json_encode(array_merge([
        "status" => $status,
        "success" => ($status === 'success'),
        "message" => $message
    ], $extra));
    exit;
}

// ─────────────────────────────────────────────────────────────
// 1. GET ALL CATEGORIES WITH MULTI-TRIP FARES
// ─────────────────────────────────────────────────────────────
if ($action === 'get_all') {
    $catQuery = "SELECT id, car_type, status FROM car_categories ORDER BY car_type ASC";
    $catRes = mysqli_query($conn, $catQuery);
    $categories = [];
    $catMap = [];

    if ($catRes) {
        while ($row = mysqli_fetch_assoc($catRes)) {
            $cName = strtoupper(trim($row['car_type']));
            $catMap[$cName] = [
                'id' => (int)$row['id'],
                'car_type' => $cName,
                'status' => $row['status'] ?? 'active',
                'oneway' => null,
                'roundtrip' => null,
                'localduty' => null
            ];
        }
    }

    $costQuery = "SELECT id, carType, tripType, kmRate, baseAmount, extraKMAmount, extraHoursAmount, 
                         packageKm, packageHours, driverRate, driver_allowance, daily_limit, gstPercent, agni_share 
                  FROM tripCostTable 
                  ORDER BY id ASC";
    $costRes = mysqli_query($conn, $costQuery);

    if ($costRes) {
        while ($cRow = mysqli_fetch_assoc($costRes)) {
            $cType = strtoupper(trim($cRow['carType'] ?? ''));
            $tType = strtolower(trim($cRow['tripType'] ?? ''));

            if (empty($cType)) continue;

            if (!isset($catMap[$cType])) {
                $catMap[$cType] = [
                    'id' => 0,
                    'car_type' => $cType,
                    'status' => 'active',
                    'oneway' => null,
                    'roundtrip' => null,
                    'localduty' => null
                ];
            }

            if (strpos($tType, 'one') !== false) {
                $catMap[$cType]['oneway'] = [
                    'id' => (int)$cRow['id'],
                    'kmRate' => (float)$cRow['kmRate'],
                    'baseAmount' => (float)$cRow['baseAmount'],
                    'driverRate' => (float)$cRow['driverRate'],
                    'gstPercent' => (float)($cRow['gstPercent'] ?? 5.0),
                    'agni_share' => (float)($cRow['agni_share'] ?? 2.0)
                ];
            } elseif (strpos($tType, 'round') !== false) {
                $catMap[$cType]['roundtrip'] = [
                    'id' => (int)$cRow['id'],
                    'kmRate' => (float)$cRow['kmRate'],
                    'driver_allowance' => (float)($cRow['driver_allowance'] ?? 400),
                    'daily_limit' => (float)($cRow['daily_limit'] ?? 300),
                    'gstPercent' => (float)($cRow['gstPercent'] ?? 5.0),
                    'agni_share' => (float)($cRow['agni_share'] ?? 2.0)
                ];
            } elseif (strpos($tType, 'local') !== false) {
                $catMap[$cType]['localduty'] = [
                    'id' => (int)$cRow['id'],
                    'baseAmount' => (float)$cRow['baseAmount'],
                    'packageKm' => (float)($cRow['packageKm'] ?? 80),
                    'packageHours' => (float)($cRow['packageHours'] ?? 8),
                    'extraKMAmount' => (float)($cRow['extraKMAmount'] ?? 15),
                    'extraHoursAmount' => (float)($cRow['extraHoursAmount'] ?? 100),
                    'driverRate' => (float)($cRow['driverRate'] ?? 2000),
                    'driver_allowance' => (float)($cRow['driver_allowance'] ?? 300),
                    'gstPercent' => (float)($cRow['gstPercent'] ?? 5.0),
                    'agni_share' => (float)($cRow['agni_share'] ?? 300)
                ];
            }
        }
    }

    $finalList = array_values($catMap);
    sendJson('success', 'Categories and trip fares loaded successfully', [
        'count' => count($finalList),
        'categories' => $finalList
    ]);
}

// ─────────────────────────────────────────────────────────────
// 2. ADD NEW CAR CATEGORY (AND AUTO-POPULATE 3 TRIP TYPES)
// ─────────────────────────────────────────────────────────────
if ($action === 'add_category') {
    $rawType = trim($params['car_type'] ?? '');
    $car_type = strtoupper(preg_replace('/[^A-Za-z0-9_\-\s]/', '', $rawType));

    if (empty($car_type)) {
        sendJson('error', 'Category name cannot be empty');
    }

    $checkStmt = $conn->prepare("SELECT id FROM car_categories WHERE UPPER(car_type) = ?");
    $checkStmt->bind_param("s", $car_type);
    $checkStmt->execute();
    $existing = $checkStmt->get_result()->fetch_assoc();
    $checkStmt->close();

    $catId = 0;
    if ($existing) {
        $catId = (int)$existing['id'];
        $upStmt = $conn->prepare("UPDATE car_categories SET status = 'active' WHERE id = ?");
        $upStmt->bind_param("i", $catId);
        $upStmt->execute();
        $upStmt->close();
    } else {
        $insStmt = $conn->prepare("INSERT INTO car_categories (car_type, status) VALUES (?, 'active')");
        $insStmt->bind_param("s", $car_type);
        $insStmt->execute();
        $catId = $conn->insert_id;
        $insStmt->close();
    }

    // One-Way
    $oneway_km_rate = (float)($params['oneway_km_rate'] ?? 14.0);
    $oneway_base_amount = (float)($params['oneway_base_amount'] ?? 1500.0);
    $oneway_driver_rate = (float)($params['oneway_driver_rate'] ?? 12.0);
    $oneway_gst = (float)($params['oneway_gst'] ?? 5.0);
    $oneway_agni = (float)($params['oneway_agni'] ?? 2.0);

    $owCheck = $conn->query("SELECT id FROM tripCostTable WHERE tripType IN ('One-way', 'One-Way') AND carType = '$car_type' LIMIT 1");
    if ($owCheck && $owCheck->num_rows > 0) {
        $owRow = $owCheck->fetch_assoc();
        $owId = $owRow['id'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET kmRate = ?, baseAmount = ?, driverRate = ?, gstPercent = ?, agni_share = ? WHERE id = ?");
        $stmt->bind_param("dddddi", $oneway_km_rate, $oneway_base_amount, $oneway_driver_rate, $oneway_gst, $oneway_agni, $owId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO tripCostTable (carType, tripType, kmRate, baseAmount, driverRate, gstPercent, agni_share) VALUES (?, 'One-way', ?, ?, ?, ?, ?)");
        $stmt->bind_param("sddddd", $car_type, $oneway_km_rate, $oneway_base_amount, $oneway_driver_rate, $oneway_gst, $oneway_agni);
        $stmt->execute();
        $stmt->close();
    }

    // B.2 Upsert into one_way_vehicle_rules for the /oneway-fare page
    $owrCheck = $conn->query("SELECT id FROM one_way_vehicle_rules WHERE car_type_id = $catId OR UPPER(car_type_label) = '$car_type' LIMIT 1");
    if ($owrCheck && $owrCheck->num_rows > 0) {
        $owrRow = $owrCheck->fetch_assoc();
        $owrId = (int)$owrRow['id'];
        $stmt = $conn->prepare("UPDATE one_way_vehicle_rules SET car_type_id = ?, car_type_label = ?, km_rate = ?, is_active = 1 WHERE id = ?");
        $stmt->bind_param("isdi", $catId, $car_type, $oneway_km_rate, $owrId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO one_way_vehicle_rules (car_type_id, car_type_label, km_rate, min_rate_multiplier, max_rate_multiplier, min_distance_km, driver_allowance_short, driver_allowance_long, distance_threshold_km, is_active, display_order) VALUES (?, ?, ?, 0.80, 1.40, 100, 300, 400, 200, 1, 10)");
        $stmt->bind_param("isd", $catId, $car_type, $oneway_km_rate);
        $stmt->execute();
        $stmt->close();
    }

    // Round-Trip
    $round_km_rate = (float)($params['round_km_rate'] ?? 13.0);
    $round_allowance = (float)($params['round_allowance'] ?? 400.0);
    $round_daily_limit = (float)($params['round_daily_limit'] ?? 300.0);
    $round_gst = (float)($params['round_gst'] ?? 5.0);
    $round_agni = (float)($params['round_agni'] ?? 2.0);

    $rtCheck = $conn->query("SELECT id FROM tripCostTable WHERE tripType IN ('Round-trip', 'Round-Trip') AND carType = '$car_type' LIMIT 1");
    if ($rtCheck && $rtCheck->num_rows > 0) {
        $rtRow = $rtCheck->fetch_assoc();
        $rtId = $rtRow['id'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET kmRate = ?, driver_allowance = ?, daily_limit = ?, gstPercent = ?, agni_share = ? WHERE id = ?");
        $stmt->bind_param("dddddi", $round_km_rate, $round_allowance, $round_daily_limit, $round_gst, $round_agni, $rtId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO tripCostTable (carType, tripType, kmRate, driver_allowance, daily_limit, gstPercent, agni_share) VALUES (?, 'Round-trip', ?, ?, ?, ?, ?)");
        $stmt->bind_param("sddddd", $car_type, $round_km_rate, $round_allowance, $round_daily_limit, $round_gst, $round_agni);
        $stmt->execute();
        $stmt->close();
    }

    // Local-Duty
    $local_base_amount = (float)($params['local_base_amount'] ?? 2200.0);
    $local_package_km = (float)($params['local_package_km'] ?? 80.0);
    $local_package_hours = (float)($params['local_package_hours'] ?? 8.0);
    $local_extra_km = (float)($params['local_extra_km'] ?? 15.0);
    $local_extra_hour = (float)($params['local_extra_hour'] ?? 100.0);
    $local_allowance = (float)($params['local_allowance'] ?? 300.0);
    $local_driver_rate = (float)($params['local_driver_rate'] ?? 1900.0);
    $local_gst = (float)($params['local_gst'] ?? 5.0);
    $local_agni = (float)($params['local_agni'] ?? 300.0);

    $ldCheck = $conn->query("SELECT id FROM tripCostTable WHERE tripType IN ('Local-Duty', 'Local-duty') AND carType = '$car_type' LIMIT 1");
    if ($ldCheck && $ldCheck->num_rows > 0) {
        $ldRow = $ldCheck->fetch_assoc();
        $ldId = $ldRow['id'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET baseAmount = ?, packageKm = ?, packageHours = ?, extraKMAmount = ?, extraHoursAmount = ?, driver_allowance = ?, driverRate = ?, gstPercent = ?, agni_share = ? WHERE id = ?");
        $stmt->bind_param("dddddddddi", $local_base_amount, $local_package_km, $local_package_hours, $local_extra_km, $local_extra_hour, $local_allowance, $local_driver_rate, $local_gst, $local_agni, $ldId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO tripCostTable (carType, tripType, baseAmount, packageKm, packageHours, extraKMAmount, extraHoursAmount, driver_allowance, driverRate, gstPercent, agni_share) VALUES (?, 'Local-Duty', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sddddddddd", $car_type, $local_base_amount, $local_package_km, $local_package_hours, $local_extra_km, $local_extra_hour, $local_allowance, $local_driver_rate, $local_gst, $local_agni);
        $stmt->execute();
        $stmt->close();
    }

    sendJson('success', "Category '{$car_type}' successfully created and synced across One-Way, Round-Trip, and Local-Duty!");
}

// ─────────────────────────────────────────────────────────────
// 3. UPDATE FARES
// ─────────────────────────────────────────────────────────────
if ($action === 'update_fares') {
    $car_type = strtoupper(trim($params['car_type'] ?? ''));
    if (empty($car_type)) {
        sendJson('error', 'Category name required');
    }

    if (isset($params['oneway'])) {
        $ow = $params['oneway'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET kmRate = ?, baseAmount = ?, driverRate = ?, gstPercent = ?, agni_share = ? WHERE tripType IN ('One-way', 'One-Way') AND carType = ?");
        $km = (float)($ow['kmRate'] ?? 14);
        $base = (float)($ow['baseAmount'] ?? 1500);
        $dr = (float)($ow['driverRate'] ?? 12);
        $gst = (float)($ow['gstPercent'] ?? 5);
        $agni = (float)($ow['agni_share'] ?? 2);
        $stmt->bind_param("ddddds", $km, $base, $dr, $gst, $agni, $car_type);
        $stmt->execute();
        $stmt->close();
    }

    if (isset($params['roundtrip'])) {
        $rt = $params['roundtrip'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET kmRate = ?, driver_allowance = ?, daily_limit = ?, gstPercent = ?, agni_share = ? WHERE tripType IN ('Round-trip', 'Round-Trip') AND carType = ?");
        $km = (float)($rt['kmRate'] ?? 13);
        $da = (float)($rt['driver_allowance'] ?? 400);
        $dl = (float)($rt['daily_limit'] ?? 300);
        $gst = (float)($rt['gstPercent'] ?? 5);
        $agni = (float)($rt['agni_share'] ?? 2);
        $stmt->bind_param("ddddds", $km, $da, $dl, $gst, $agni, $car_type);
        $stmt->execute();
        $stmt->close();
    }

    if (isset($params['localduty'])) {
        $ld = $params['localduty'];
        $stmt = $conn->prepare("UPDATE tripCostTable SET baseAmount = ?, packageKm = ?, packageHours = ?, extraKMAmount = ?, extraHoursAmount = ?, driver_allowance = ?, driverRate = ?, gstPercent = ?, agni_share = ? WHERE tripType IN ('Local-Duty', 'Local-duty') AND carType = ?");
        $base = (float)($ld['baseAmount'] ?? 2200);
        $pKm = (float)($ld['packageKm'] ?? 80);
        $pHr = (float)($ld['packageHours'] ?? 8);
        $eKm = (float)($ld['extraKMAmount'] ?? 15);
        $eHr = (float)($ld['extraHoursAmount'] ?? 100);
        $da = (float)($ld['driver_allowance'] ?? 300);
        $dr = (float)($ld['driverRate'] ?? 1900);
        $gst = (float)($ld['gstPercent'] ?? 5);
        $agni = (float)($ld['agni_share'] ?? 300);
        $stmt->bind_param("ddddddddds", $base, $pKm, $pHr, $eKm, $eHr, $da, $dr, $gst, $agni, $car_type);
        $stmt->execute();
        $stmt->close();
    }

    sendJson('success', "Fares for '{$car_type}' updated successfully!");
}

// ─────────────────────────────────────────────────────────────
// 4. TOGGLE STATUS
// ─────────────────────────────────────────────────────────────
if ($action === 'toggle_status') {
    $id = (int)($params['id'] ?? 0);
    $status = strtolower(trim($params['status'] ?? 'active'));
    if (!in_array($status, ['active', 'blocked'])) {
        $status = 'active';
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE car_categories SET status = ? WHERE id = ?");
        $stmt->bind_param("si", $status, $id);
        $stmt->execute();
        $stmt->close();
        sendJson('success', "Category status changed to {$status}");
    }
    sendJson('error', 'Invalid category ID');
}

// ─────────────────────────────────────────────────────────────
// 5. DELETE CATEGORY
// ─────────────────────────────────────────────────────────────
if ($action === 'delete_category') {
    $car_type = strtoupper(trim($params['car_type'] ?? ''));
    if (empty($car_type)) {
        sendJson('error', 'Category name required');
    }

    $stmt = $conn->prepare("DELETE FROM car_categories WHERE UPPER(car_type) = ?");
    $stmt->bind_param("s", $car_type);
    $stmt->execute();
    $stmt->close();

    $stmt2 = $conn->prepare("DELETE FROM tripCostTable WHERE UPPER(carType) = ?");
    $stmt2->bind_param("s", $car_type);
    $stmt2->execute();
    $stmt2->close();

    sendJson('success', "Category '{$car_type}' deleted successfully from categories and fare tables.");
}

sendJson('error', "Invalid action: {$action}");
?>
