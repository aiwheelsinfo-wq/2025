<?php

header('Content-Type: application/json');
include 'db_connect.php';

// Discount percentage
$discount_percentage = 10;

// Get parameters
$tripType  = $_GET['tripType'] ?? '';
$bookingId = isset($_GET['bookingId']) ? intval($_GET['bookingId']) : 0;

$fromLat = isset($_GET['fromLat']) ? floatval($_GET['fromLat']) : null;
$fromLon = isset($_GET['fromLng']) ? floatval($_GET['fromLng']) : null;
$toLat   = isset($_GET['toLat']) ? floatval($_GET['toLat']) : null;
$toLon   = isset($_GET['toLng']) ? floatval($_GET['toLng']) : null;

// If bookingId exists but no coordinates, fetch from DB & geocode
if ($bookingId > 0 && ($fromLat === null || $toLat === null)) {
    $stmt = $conn->prepare("SELECT from_address, to_address FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $bookingId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $fromAddress = urlencode($row['from_address']);
        $toAddress = urlencode($row['to_address']);
        $apiKey = 'AIzaSyAjKSgnz_ll5wvTSaIVypYnqhIdSWNGeiQ';

        if (!empty($row['from_address'])) {
            $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$fromAddress&key=$apiKey";
            $geoResponse = file_get_contents($geoUrl);
            $geoData = json_decode($geoResponse, true);
            if ($geoData['status'] === 'OK') {
                $fromLat = $geoData['results'][0]['geometry']['location']['lat'];
                $fromLon = $geoData['results'][0]['geometry']['location']['lng'];
            }
        }

        if (!empty($row['to_address'])) {
            $geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=$toAddress&key=$apiKey";
            $geoResponse = file_get_contents($geoUrl);
            $geoData = json_decode($geoResponse, true);
            if ($geoData['status'] === 'OK') {
                $toLat = $geoData['results'][0]['geometry']['location']['lat'];
                $toLon = $geoData['results'][0]['geometry']['location']['lng'];
            }
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
            $baseAmount = floatval($row['baseAmount']);
            $discounted_price = $baseAmount + ($baseAmount * $discount_percentage / 100);

            $cars[$row['carType']] = [
                'carType' => $row['carType'],
                'kmRate' => $row['kmRate'],
                'baseAmount' => number_format($baseAmount, 0, '.', ''),
                'extraKMAmount' => $row['extraKMAmount'],
                'extraHoursAmount' => $row['extraHoursAmount'],
                'packageKm' => $row['packageKm'],
                'packageHours' => $row['packageHours'],
                'gstPercent' => $row['gstPercent'],
                'driverAllowance' => $row['driver_allowance'],
                'kmPerDay' => $row['daily_limit'],
                'driverRate' => $row['driverRate'],
                'agni_share' => $row['agni_share'],
                'discounted_price' => number_format($discounted_price, 0, '.', ''),
                'discount_percentage' => $discount_percentage
            ];
        }
    }

    // If no coordinates matched, use first 5 default cars
    if (!$found) {
        foreach ($defaultCars as $carType => $row) {
            $baseAmount = floatval($row['baseAmount']);
            $discounted_price = $baseAmount + ($baseAmount * $discount_percentage / 100);

            $cars[$row['carType']] = [
                'carType' => $row['carType'],
                'kmRate' => $row['kmRate'],
                'baseAmount' => number_format($baseAmount, 0, '.', ''),
                'extraKMAmount' => $row['extraKMAmount'],
                'extraHoursAmount' => $row['extraHoursAmount'],
                'packageKm' => $row['packageKm'],
                'packageHours' => $row['packageHours'],
                'gstPercent' => $row['gstPercent'],
                'driverAllowance' => $row['driver_allowance'],
                'kmPerDay' => $row['daily_limit'],
                'driverRate' => $row['driverRate'],
                'agni_share' => $row['agni_share'],
                'discounted_price' => number_format($discounted_price, 0, '.', ''),
                'discount_percentage' => $discount_percentage
            ];
        }
    }
}

echo json_encode(array_values($cars), JSON_PRETTY_PRINT);
$conn->close();
