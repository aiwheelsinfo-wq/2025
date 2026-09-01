<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

// Get parameters
$tripType  = $_GET['tripType'] ?? '';

// Discount percentage
$discount_percentage = ($tripType === 'Local-Duty') ? 0 : 10;
$discount_type = 'percentage';
$discount_value = ($tripType === 'Local-Duty') ? 0 : 10;

if ($tripType === 'One-way') {
    $discount_percentage = 0;
    $discount_value = 0;
    $today = date('Y-m-d');
    $tableCheck = $conn->query("SHOW TABLES LIKE 'discounts'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $discount_query = "SELECT discount_type, discount_value FROM discounts WHERE status = 'active' AND apply_scope = 'One-way' AND '$today' BETWEEN start_date AND end_date ORDER BY id DESC LIMIT 1";
        $discount_res = $conn->query($discount_query);
        if ($discount_res && $discount_res->num_rows > 0) {
            $row_disc = $discount_res->fetch_assoc();
            $discount_type = $row_disc['discount_type'];
            $discount_value = floatval($row_disc['discount_value']);
            if ($discount_type === 'percentage') {
                $discount_percentage = $discount_value;
            }
        }
    }
}
$bookingId = isset($_GET['bookingId']) ? intval($_GET['bookingId']) : 0;

$fromLat = isset($_GET['fromLat']) ? floatval($_GET['fromLat']) : null;
$fromLon = isset($_GET['fromLng']) ? floatval($_GET['fromLng']) : null;
$toLat   = isset($_GET['toLat']) ? floatval($_GET['toLat']) : null;
$toLon   = isset($_GET['toLng']) ? floatval($_GET['toLng']) : null;

$distance_km = 100; // Default fallback distance in km
$apiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';

$rawFromAddress = $_GET['fromAddress'] ?? $_GET['from_address'] ?? '';
$rawToAddress   = $_GET['toAddress'] ?? $_GET['to_address'] ?? '';

// 1. Direct explicit distance parameter passed by frontend
if (isset($_GET['distance']) && floatval($_GET['distance']) > 0 && floatval($_GET['distance']) < 900) {
    $distance_km = floatval($_GET['distance']);
} 
// 2. Direct fromAddress & toAddress passed by frontend
elseif (!empty($rawFromAddress) && !empty($rawToAddress)) {
    $fromEnc = urlencode($rawFromAddress);
    $toEnc = urlencode($rawToAddress);
    $distUrl = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$fromEnc&destinations=$toEnc&key=$apiKey";
    $distResponse = @file_get_contents($distUrl);
    if ($distResponse) {
        $distData = json_decode($distResponse, true);
        if ($distData['status'] === 'OK' && !empty($distData['rows'][0]['elements'][0]['distance']['text'])) {
            $distanceText = $distData['rows'][0]['elements'][0]['distance']['text'];
            $cleanText = str_replace([',', ' '], '', explode(' ', $distanceText)[0]);
            $distance_km = floatval($cleanText);
        }
    }
}
// 3. Fallback: Calculate distance from passed coordinates
elseif ($fromLat !== null && $toLat !== null) {
    $distUrl = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$fromLat,$fromLon&destinations=$toLat,$toLon&key=$apiKey";
    $distResponse = @file_get_contents($distUrl);
    if ($distResponse) {
        $distData = json_decode($distResponse, true);
        if ($distData['status'] === 'OK' && !empty($distData['rows'][0]['elements'][0]['distance']['text'])) {
            $distanceText = $distData['rows'][0]['elements'][0]['distance']['text'];
            $cleanText = str_replace([',', ' '], '', explode(' ', $distanceText)[0]);
            $distance_km = floatval($cleanText);
        }
    }
}
// 4. Fallback to bookingId if none of the above are provided
elseif ($bookingId > 0) {
    $stmt = $conn->prepare("SELECT from_address, to_address FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $fromAddress = urlencode($row['from_address']);
        $toAddress = urlencode($row['to_address']);

        if (!empty($row['from_address']) && !empty($row['to_address'])) {
            $distUrl = "https://maps.googleapis.com/maps/api/distancematrix/json?origins=$fromAddress&destinations=$toAddress&key=$apiKey";
            $distResponse = @file_get_contents($distUrl);
            if ($distResponse) {
                $distData = json_decode($distResponse, true);
                if ($distData['status'] === 'OK' && !empty($distData['rows'][0]['elements'][0]['distance']['text'])) {
                    $distanceText = $distData['rows'][0]['elements'][0]['distance']['text'];
                    $cleanText = str_replace([',', ' '], '', explode(' ', $distanceText)[0]);
                    $distance_km = floatval($cleanText);
                }
            }
        }
    }
    if ($stmt) $stmt->close();
}

// -------------------------------------------------------------
// ONE-WAY DYNAMIC PRICING & FARE CALCULATION ENGINE
// -------------------------------------------------------------
if ($tripType === 'One-way') {
    require_once __DIR__ . '/OneWayFareCalculator.php';
    require_once __DIR__ . '/MigrationRunner.php';
    MigrationRunner::run($conn);

    $cars = [];
    $rulesQuery = "SELECT * FROM `one_way_vehicle_rules` WHERE `is_active` = 1 ORDER BY `display_order` ASC, `id` ASC";
    $rulesRes = mysqli_query($conn, $rulesQuery);

    if ($rulesRes && mysqli_num_rows($rulesRes) > 0) {
        $gRes = mysqli_query($conn, "SELECT * FROM `one_way_global_settings` WHERE `id` = 1 LIMIT 1");
        $global = $gRes ? mysqli_fetch_assoc($gRes) : [];
        $targetDate = $_GET['pickupDate'] ?? $_GET['pickup_date'] ?? $_GET['date'] ?? date('Y-m-d');

        while ($rule = mysqli_fetch_assoc($rulesRes)) {
            $carType = $rule['car_type_label'];
            $carTypeId = (int)$rule['car_type_id'];

            // 1. Calculate live dynamic fare with active settings for the specific requested travel date
            $calcRes = OneWayFareCalculator::calculate($conn, $carTypeId, $distance_km, $fromAddress ?? '', $toAddress ?? '', [], $targetDate);

            // 2. Calculate baseline static fare without dynamic adjustments (to determine baseline/strikethrough price)
            $staticOverrides = array_merge($global, ['dynamic_pricing_active' => 0, 'discount_active' => 0]);
            $staticRes = OneWayFareCalculator::calculate($conn, $carTypeId, $distance_km, $fromAddress ?? '', $toAddress ?? '', $staticOverrides, $targetDate);

            $finalFare = (float)$calcRes['final_fare'];
            $staticFare = (float)$staticRes['final_fare'];

            // Check if discounted (due to dynamic low demand OR promo discount)
            $hasDiscount = ($finalFare < $staticFare);
            $discountPct = 0.0;
            if ($hasDiscount && $staticFare > 0) {
                $discountPct = round((($staticFare - $finalFare) / $staticFare) * 100, 1);
            }

            // Map legacy metadata if available
            $legacyMetaRes = mysqli_query($conn, "SELECT extraKMAmount, extraHoursAmount, packageHours, daily_limit, driverRate, agni_share FROM tripCostTable WHERE tripType='One-way' AND carType='$carType' LIMIT 1");
            $meta = ($legacyMetaRes && $mRow = mysqli_fetch_assoc($legacyMetaRes)) ? $mRow : [];

            $cars[$carType] = [
                'carType' => $carType,
                'kmRate' => (string)$calcRes['km_rate'],
                'baseAmount' => (string)number_format($staticFare, 2, '.', ''),
                'extraKMAmount' => (string)($meta['extraKMAmount'] ?? $calcRes['km_rate']),
                'extraHoursAmount' => (string)($meta['extraHoursAmount'] ?? 0),
                'packageKm' => round($distance_km),
                'packageHours' => (string)($meta['packageHours'] ?? '0'),
                'gstPercent' => (string)$calcRes['gst_breakdown']['rate'],
                'driverAllowance' => (string)number_format($calcRes['driver_allowance'], 2, '.', ''),
                'driverAllowanceActive' => $calcRes['driver_allowance_active'] ? 1 : 0,
                'tollCharge' => (string)number_format($calcRes['toll_charge'], 2, '.', ''),
                'parkingCharge' => (string)number_format($calcRes['parking_charge'], 2, '.', ''),
                'kmPerDay' => (string)($meta['daily_limit'] ?? '250'),
                'driverRate' => (string)($meta['driverRate'] ?? '0'),
                'agni_share' => (string)($meta['agni_share'] ?? '0'),
                'discounted_price' => (string)number_format($finalFare, 2, '.', ''),
                'discount_percentage' => $discountPct,
                'is_discounted' => $hasDiscount ? 1 : 0,
                'company_share_amount' => (string)number_format($calcRes['company_share_amount'], 2, '.', ''),
                'driver_payout_amount' => (string)number_format($calcRes['driver_payout_amount'], 2, '.', ''),
                'dynamic_pricing' => $calcRes['dynamic_pricing'] ?? null,
            ];
        }

        echo json_encode(array_values($cars), JSON_PRETTY_PRINT);
        $conn->close();
        exit;
    }
}

// Fetch all trip costs for this tripType
$sql = "SELECT * FROM tripCostTable WHERE tripType='$tripType' ORDER BY id ASC";
$result = $conn->query($sql);

$cars = [];
$defaultCars = []; // Store first 5 rows with NULL coordinates
$found = false;    // Flag to check if coordinates match any bounding box

if ($result && $result->num_rows > 0) {
    $defaultCounter = 0;

    while ($row = $result->fetch_assoc()) {
        $hasCoords = $row['minLat'] !== null && $row['maxLat'] !== null && $row['minLon'] !== null && $row['maxLon'] !== null;
        $useRow = false;

        // Check if posted coordinates are within bounding box
        if ($hasCoords && $tripType === 'One-way') {
            if (($fromLat !== null && $fromLon !== null &&
                 $fromLat >= $row['minLat'] && $fromLat <= $row['maxLat'] &&
                 $fromLon >= $row['minLon'] && $fromLon <= $row['maxLon'])
                ||
                ($toLat !== null && $toLon !== null &&
                 $toLat >= $row['minLat'] && $toLat <= $row['maxLat'] &&
                 $toLon >= $row['minLon'] && $toLon <= $row['maxLon'])
            ) {
                $useRow = true;
                $found = true;
            }
        }

        // Collect first 5 default cars (with NULL coords)
        if (!$hasCoords && $defaultCounter < 5) {
            $defaultCars[$row['carType']] = $row;
            $defaultCounter++;
        }

        if ($useRow) {
            if ($tripType === 'One-way') {
                $kmRate = floatval($row['kmRate']);
                $driverAllowance = floatval($row['driver_allowance'] ?? 0);
                if ($driverAllowance == 0) {
                    $driverAllowance = ($distance_km < 200) ? 300 : 400;
                }
                $tollCharge = $distance_km * 2.25;
                $baseCharge = $driverAllowance + $tollCharge;
                $standardPrice = ($kmRate * $distance_km * 1.05) + $baseCharge;
                
                // Apply discounts
                $savings = 0.0;
                if ($discount_value > 0) {
                    if ($discount_type === 'fixed') {
                        $savings = $discount_value;
                    } else {
                        $savings = $standardPrice * ($discount_value / 100);
                    }
                }
                $totalPrice = ($standardPrice - $savings < 0) ? 0.0 : ($standardPrice - $savings);
            } else {
                $standardPrice = floatval($row['baseAmount']);
                // For other types, apply percentage discount
                $savings = 0.0;
                if ($discount_value > 0) {
                    if ($discount_type === 'fixed') {
                        $savings = $discount_value;
                    } else {
                        $savings = $standardPrice * ($discount_value / 100);
                    }
                }
                $totalPrice = ($standardPrice - $savings < 0) ? 0.0 : ($standardPrice - $savings);
            }

            $cars[$row['carType']] = [
                'carType' => $row['carType'],
                'kmRate' => $row['kmRate'],
                'baseAmount' => number_format($standardPrice, 0, '.', ''),
                'extraKMAmount' => $row['extraKMAmount'],
                'extraHoursAmount' => $row['extraHoursAmount'],
                'packageKm' => ($tripType === 'One-way') ? round($distance_km) : $row['packageKm'],
                'packageHours' => $row['packageHours'],
                'gstPercent' => $row['gstPercent'],
                'driverAllowance' => ($tripType === 'One-way') ? number_format($driverAllowance, 0, '.', '') : $row['driver_allowance'],
                'kmPerDay' => $row['daily_limit'],
                'driverRate' => $row['driverRate'],
                'agni_share' => $row['agni_share'],
                'discounted_price' => number_format($totalPrice, 0, '.', ''),
                'discount_percentage' => $discount_percentage
            ];
        }
    }

    // If no coordinates matched, use first 5 default cars
    if (!$found) {
        foreach ($defaultCars as $carType => $row) {
            if ($tripType === 'One-way') {
                $kmRate = floatval($row['kmRate']);
                $driverAllowance = floatval($row['driver_allowance'] ?? 0);
                if ($driverAllowance == 0) {
                    $driverAllowance = ($distance_km < 200) ? 300 : 400;
                }
                $tollCharge = $distance_km * 2.25;
                $baseCharge = $driverAllowance + $tollCharge;
                $standardPrice = ($kmRate * $distance_km * 1.05) + $baseCharge;
                
                // Apply discounts
                $savings = 0.0;
                if ($discount_value > 0) {
                    if ($discount_type === 'fixed') {
                        $savings = $discount_value;
                    } else {
                        $savings = $standardPrice * ($discount_value / 100);
                    }
                }
                $totalPrice = ($standardPrice - $savings < 0) ? 0.0 : ($standardPrice - $savings);
            } else {
                $standardPrice = floatval($row['baseAmount']);
                // For other types, apply percentage discount
                $savings = 0.0;
                if ($discount_value > 0) {
                    if ($discount_type === 'fixed') {
                        $savings = $discount_value;
                    } else {
                        $savings = $standardPrice * ($discount_value / 100);
                    }
                }
                $totalPrice = ($standardPrice - $savings < 0) ? 0.0 : ($standardPrice - $savings);
            }

            $cars[$row['carType']] = [
                'carType' => $row['carType'],
                'kmRate' => $row['kmRate'],
                'baseAmount' => number_format($standardPrice, 0, '.', ''),
                'extraKMAmount' => $row['extraKMAmount'],
                'extraHoursAmount' => $row['extraHoursAmount'],
                'packageKm' => ($tripType === 'One-way') ? round($distance_km) : $row['packageKm'],
                'packageHours' => $row['packageHours'],
                'gstPercent' => $row['gstPercent'],
                'driverAllowance' => ($tripType === 'One-way') ? number_format($driverAllowance, 0, '.', '') : $row['driver_allowance'],
                'kmPerDay' => $row['daily_limit'],
                'driverRate' => $row['driverRate'],
                'agni_share' => $row['agni_share'],
                'discounted_price' => number_format($totalPrice, 0, '.', ''),
                'discount_percentage' => $discount_percentage
            ];
        }
    }
}

echo json_encode(array_values($cars), JSON_PRETTY_PRINT);
$conn->close();
?>
