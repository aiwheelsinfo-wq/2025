<?php
require_once __DIR__ . '/vendor/autoload.php';
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
    
    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        error_log("FCM Error for token $token: " . $error_msg);
    } elseif ($httpCode !== 200) {
        error_log("FCM Error Response for token $token (HTTP $httpCode): " . $result);
    }
    
    curl_close($ch);
    return $result;
}

function trigger_new_booking_notification($booking_id, $ref_lat = null, $ref_lon = null) {
    global $conn; // Access the database connection from the parent scope
    
    if (empty($booking_id)) {
        error_log("Notification Error: Empty booking ID");
        return;
    }
    
    // 1. Fetch booking details
    $stmt = $conn->prepare("SELECT id, trip_type, from_address, to_address, vendor_amount FROM bookings WHERE id = ?");
    if (!$stmt) {
        error_log("Notification DB Error: " . $conn->error);
        return;
    }
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        error_log("Notification Error: Booking not found for ID " . $booking_id);
        $stmt->close();
        return;
    }
    
    $booking = $res->fetch_assoc();
    $stmt->close();
    
    $booking_id_str = (string)$booking['id'];
    $trip_type = $booking['trip_type'] ?? '';
    $pickup_location = $booking['from_address'] ?? '';
    $drop_location = $booking['to_address'] ?? '';
    $vendor_amount = $booking['vendor_amount'] ?? '0.00';

    // Geocode customer's pickup address using Google Geocoding API if coordinates are not provided
    if ($ref_lat === null || $ref_lon === null) {
        $googleMapsApiKey = 'AIzaSyC41U3p08LqY8G15ruxDCEfTvBLkG_OrsM';
        $geocodeUrl = "https://maps.googleapis.com/maps/api/geocode/json?address=" . urlencode($pickup_location) . "&key=$googleMapsApiKey";
        
        $ref_lat = null;
        $ref_lon = null;
        try {
            $geoResponse = file_get_contents($geocodeUrl);
            if ($geoResponse) {
                $geoData = json_decode($geoResponse, true);
                if ($geoData['status'] === 'OK') {
                    $ref_lat = $geoData['results'][0]['geometry']['location']['lat'];
                    $ref_lon = $geoData['results'][0]['geometry']['location']['lng'];
                }
            }
        } catch (Throwable $e) {
            error_log("Geocoding failed for notification: " . $e->getMessage());
        }
    }
    
    // 2. Fetch active vendors/drivers with FCM tokens, latitude, and longitude
    // Includes userType='vendor' AND empty userType (some vendors register without userType set)
    $vendors_sql = "SELECT fcm_token, latitude, longitude FROM drivers WHERE status = 'active' AND (userType = 'vendor' OR userType = '' OR userType IS NULL) AND fcm_token IS NOT NULL AND fcm_token != ''";
    $vendors_res = mysqli_query($conn, $vendors_sql);
    if (!$vendors_res) {
        error_log("Notification DB Error fetching vendors: " . mysqli_error($conn));
        return;
    }
    
    $tokens = [];
    $radius_km = 20;
    while ($row = mysqli_fetch_assoc($vendors_res)) {
        if ($ref_lat !== null && $ref_lon !== null && 
            !empty($row['latitude']) && !empty($row['longitude']) && 
            floatval($row['latitude']) != 0 && floatval($row['longitude']) != 0) {
            $distance = getDistance($ref_lat, $ref_lon, $row['latitude'], $row['longitude']);
            if ($distance > $radius_km) {
                continue; // Skip vendors further than 20km
            }
        }
        $tokens[] = $row['fcm_token'];
    }
    
    if (empty($tokens)) {
        error_log("Notification Info: No active vendors within 20km with FCM tokens found.");
        return;
    }
    
    // 3. Prepare FCM message
    $keyFileContent = json_decode(file_get_contents(__DIR__ . '/agni-car-app-firebase-adminsdk-fbsvc-4f70f7d1f2.json'), true);
    $projectId = $keyFileContent['project_id'] ?? 'agnicarrentaldriver-8fb07';
    
    $bodyText = "From: " . $pickup_location;
    if (!empty($drop_location)) {
        $bodyText .= "\nTo: " . $drop_location;
    }
    $bodyText .= "\nEarnings: ₹" . number_format((float)$vendor_amount, 2);

    $notificationData = [
        'title' => 'New Trip Available - ' . $trip_type,
        'body' => $bodyText,
        'data' => [
            'booking_id' => $booking_id_str,
            'booking_type' => $trip_type,
            'pickup_location' => $pickup_location,
            'drop_location' => $drop_location,
            'vendor_amount' => (string)$vendor_amount,
            'notification_type' => 'new_booking'
        ]
    ];
    
    // Get FCM access token
    try {
        $accessToken = getFcmAccessToken();
    } catch (Exception $e) {
        error_log("FCM Auth Error getting access token: " . $e->getMessage());
        return;
    }
    
    // 4. Send one request per vendor token, auto-cleanup invalid tokens
    foreach ($tokens as $token) {
        $token = trim($token);
        if (empty($token)) {
            continue; // Skip invalid or empty FCM tokens
        }
        try {
            $result = sendSingleFcmNotification($accessToken, $projectId, $token, $notificationData);
            // Auto-cleanup invalid tokens from database
            if ($result) {
                $decoded = json_decode($result, true);
                $errorCode = $decoded['error']['details'][0]['errorCode'] ?? null;
                $httpStatus = $decoded['error']['status'] ?? null;
                if ($errorCode === 'UNREGISTERED' || $errorCode === 'SENDER_ID_MISMATCH') {
                    // Clean up this invalid token to prevent future failed sends
                    $clean_stmt = $conn->prepare("UPDATE drivers SET fcm_token = NULL WHERE fcm_token = ?");
                    if ($clean_stmt) {
                        $clean_stmt->bind_param("s", $token);
                        $clean_stmt->execute();
                        $clean_stmt->close();
                        error_log("FCM: Cleaned up invalid token ($errorCode): " . substr($token, 0, 30) . "...");
                    }
                }
            }
        } catch (Throwable $e) {
            error_log("FCM Exception sending to token $token: " . $e->getMessage());
            // Continue sending notifications even if one vendor notification fails
        }
    }
}
?>
