<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: text/plain');

include 'db_connect.php';
require_once 'vendor/autoload.php';
use Google\Auth\Credentials\ServiceAccountCredentials;

if (!function_exists('getDistance')) {
    function getDistance($lat1, $lon1, $lat2, $lon2) {
        $earth_radius = 6371; // Earth radius in km
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_radius * $c; // Distance in km
    }
}

function getFcmAccessToken() {
    $googleAccountKeyFilePath = __DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json';
    if (!file_exists($googleAccountKeyFilePath)) {
        throw new Exception("Google Service Account Key file not found: " . $googleAccountKeyFilePath);
    }
    $scopes = ['https://www.googleapis.com/auth/firebase.messaging'];
    $credentials = new ServiceAccountCredentials($scopes, $googleAccountKeyFilePath);
    $accessToken = $credentials->fetchAuthToken();
    return $accessToken['access_token'];
}

function sendSingleFcmNotification($accessToken, $projectId, $token, $notificationData) {
    $url = 'https://fcm.googleapis.com/v1/projects/' . $projectId . '/messages:send';
    
    $messagePayload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $notificationData['title'],
                'body' => $notificationData['body'],
            ],
            'data' => $notificationData['data']
        ],
    ];

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($messagePayload));

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'response' => json_decode($result, true), 'raw' => $result];
}

// 1. Fetch latest booking
$booking_query = mysqli_query($conn, "SELECT id, trip_type, from_address, to_address, vendor_amount FROM bookings ORDER BY id DESC LIMIT 1");
if (!$booking_query) {
    die("Query failed: " . mysqli_error($conn) . "\n");
}
if (mysqli_num_rows($booking_query) === 0) {
    die("No bookings found in database. Num rows is 0.\n");
}
$booking = mysqli_fetch_assoc($booking_query);
echo "=== LATEST BOOKING DETAILS ===\n";
echo "ID: " . $booking['id'] . "\n";
echo "Trip Type: " . $booking['trip_type'] . "\n";
echo "Pickup: " . $booking['from_address'] . "\n";
echo "Drop: " . $booking['to_address'] . "\n";
echo "Amount: " . $booking['vendor_amount'] . "\n\n";

// 2. Geocode pickup location
$googleMapsApiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
$geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($booking['from_address']) . "&key=$googleMapsApiKey";

$ref_lat = null;
$ref_lon = null;
echo "=== GEOCODING PICKUP LOCATION ===\n";
try {
    $geoResponse = file_get_contents($geocodeUrl);
    if ($geoResponse) {
        $geoData = json_decode($geoResponse, true);
        if ($geoData['status'] === 'OK') {
            $ref_lat = $geoData['results'][0]['geometry']['location']['lat'];
            $ref_lon = $geoData['results'][0]['geometry']['location']['lng'];
            echo "Success: Lat: $ref_lat, Lon: $ref_lon\n\n";
        } else {
            echo "Geocoding returned status: " . $geoData['status'] . "\n\n";
        }
    } else {
        echo "Failed to contact Google Geocoding API.\n\n";
    }
} catch (Throwable $e) {
    echo "Geocoding Exception: " . $e->getMessage() . "\n\n";
}

// 3. Fetch active vendors/drivers
echo "=== VENDORS DISTANCE EVALUATION ===\n";
$vendors_sql = "SELECT driver_id, full_name, phone_number, fcm_token, latitude, longitude, userType FROM drivers WHERE status = 'active' AND (userType = 'vendor' OR userType = '' OR userType IS NULL) AND fcm_token IS NOT NULL AND fcm_token != ''";
$vendors_res = mysqli_query($conn, $vendors_sql);
if (!$vendors_res) {
    die("DB Query error: " . mysqli_error($conn) . "\n");
}

$tokens_to_notify = [];
$radius_km = 20;
while ($row = mysqli_fetch_assoc($vendors_res)) {
    $lat = floatval($row['latitude']);
    $lon = floatval($row['longitude']);
    echo "Driver ID: " . $row['driver_id'] . " | Name: " . $row['full_name'] . " | Lat: $lat, Lon: $lon | Type: " . $row['userType'] . "\n";
    
    // Check for "Null Island" coordinates (0,0) or uninitialized
    if ($lat == 0.0 && $lon == 0.0) {
        echo "  -> Coordinates are 0,0 (uninitialized). BYPASSING distance check (including vendor).\n";
        $tokens_to_notify[$row['fcm_token']] = $row['full_name'];
    } else {
        if ($ref_lat !== null && $ref_lon !== null) {
            $distance = getDistance($ref_lat, $ref_lon, $lat, $lon);
            echo "  -> Calculated Distance: " . round($distance, 2) . " km\n";
            if ($distance <= $radius_km) {
                echo "  -> WITHIN 20km Geofence. INCLUDING vendor.\n";
                $tokens_to_notify[$row['fcm_token']] = $row['full_name'];
            } else {
                echo "  -> OUTSIDE Geofence. EXCLUDING vendor.\n";
            }
        } else {
            echo "  -> Pickup geocoding failed/null. INCLUDING vendor by default.\n";
            $tokens_to_notify[$row['fcm_token']] = $row['full_name'];
        }
    }
}
echo "\n";

// 4. Try sending FCM
echo "=== SENDING FCM MESSAGES ===\n";
if (empty($tokens_to_notify)) {
    echo "No vendors to notify.\n";
} else {
    try {
        $accessToken = getFcmAccessToken();
        $keyFileContent = json_decode(file_get_contents(__DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json'), true);
        $projectId = $keyFileContent['project_id'] ?? 'agnicarrentaldriver-8fb07';
        
        $bodyText = "From: " . $booking['from_address'] . "\nEarnings: ₹" . number_format((float)$booking['vendor_amount'], 2);
        $notificationData = [
            'title' => 'New Trip Available - ' . $booking['trip_type'],
            'body' => $bodyText,
            'data' => [
                'booking_id' => (string)$booking['id'],
                'booking_type' => $booking['trip_type'],
                'pickup_location' => $booking['from_address'],
                'drop_location' => $booking['to_address'],
                'vendor_amount' => (string)$booking['vendor_amount'],
                'notification_type' => 'new_booking'
            ]
        ];

        foreach ($tokens_to_notify as $token => $name) {
            echo "Sending to: $name (" . substr($token, 0, 15) . "...)\n";
            $res = sendSingleFcmNotification($accessToken, $projectId, $token, $notificationData);
            echo "  -> HTTP Code: " . $res['code'] . "\n";
            echo "  -> Response: " . json_encode($res['response']) . "\n";
        }
    } catch (Throwable $e) {
        echo "FCM Send Exception: " . $e->getMessage() . "\n";
    }
}

mysqli_close($conn);
?>
