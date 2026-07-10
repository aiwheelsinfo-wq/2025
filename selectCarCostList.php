<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

include 'db_connect.php';

// Discount percentage
$discount_percentage = 10;
$discount_type = 'percentage';
$discount_value = 10;

// Get parameters
$tripType  = $_GET['tripType'] ?? '';

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

// If bookingId exists but no coordinates, fetch from DB & geocode
if ($bookingId > 0) {
    $stmt = $conn->prepare("SELECT from_address, to_address FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $fromAddress = urlencode($row['from_address']);
        $toAddress = urlencode($row['to_address']);

        if (!empty($row['from_address']) && $fromLat === null) {
            $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$fromAddress&key=$apiKey";
            $geoResponse = @file_get_contents($geoUrl);
            if ($geoResponse) {
                $geoData = json_decode($geoResponse, true);
                if ($geoData['status'] === 'OK') {
                    $fromLat = $geoData['results'][0]['geometry']['location']['lat'];
                    $fromLon = $geoData['results'][0]['geometry']['location']['lng'];
                }
            }
        }

        if (!empty($row['to_address']) && $toLat === null) {
            $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$toAddress&key=$apiKey";
            $geoResponse = @file_get_contents($geoUrl);
            if ($geoResponse) {
                $geoData = json_decode($geoResponse, true);
                if ($geoData['status'] === 'OK') {
                    $toLat = $geoData['results'][0]['geometry']['location']['lat'];
                    $toLon = $geoData['results'][0]['geometry']['location']['lng'];
                }
            }
        }

        // Fetch distance from Google Distance Matrix API
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

// Fallback: Calculate distance from passed coordinates if bookingId didn't yield it
if (($fromLat !== null && $toLat !== null) && $distance_km == 100) {
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
